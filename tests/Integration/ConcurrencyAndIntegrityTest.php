<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional\AdminOrderTestTrait;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\CompletionResult;
use Tmonier\SyliusAdminOrderCreationPlugin\Remover\DraftRemoverInterface;

final class ConcurrencyAndIntegrityTest extends WebTestCase
{
    use AdminOrderTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testASingleUseCouponCannotBeUsedByTwoDrafts(): void
    {
        $this->createSingleUseCoupon('ONCE');
        $first = $this->prepareDraftReadyToComplete('first.coupon@example.com');
        $second = $this->prepareDraftReadyToComplete('second.coupon@example.com');
        foreach ([$first, $second] as $orderId) {
            $order = $this->reloadOrder($orderId);
            self::assertTrue($this->draftModifier()->applyCoupon($order, 'ONCE'), 'Both drafts accept the unused coupon.');
            $this->reselectMethods($order);
        }

        self::assertTrue($this->complete($first)->isPlaced());

        $result = $this->complete($second);
        self::assertSame(CompletionResult::CHANGED, $result->status);
        self::assertSame('Single use', $result->removedPromotion);
        self::assertSame(OrderInterface::STATE_CART, $this->reloadOrder($second)->getState());

        self::assertTrue($this->complete($second)->isPlaced(), 'Once reviewed, the draft is placed without the promotion.');
        self::assertCount(0, $this->reloadOrder($second)->getPromotions()->filter(static fn ($promotion): bool => 'ONCE_PROMO' === $promotion->getCode()));
        self::assertSame(1, $this->entityManager()->getRepository(PromotionCouponInterface::class)->findOneBy(['code' => 'ONCE'])?->getUsed());
    }

    public function testACouponUsedByAnotherDraftCommittedMeanwhileIsDetected(): void
    {
        $this->createSingleUseCoupon('RACE');
        $first = $this->prepareDraftReadyToComplete('first.race@example.com');
        $second = $this->prepareDraftReadyToComplete('second.race@example.com');
        foreach ([$first, $second] as $orderId) {
            $order = $this->reloadOrder($orderId);
            self::assertTrue($this->draftModifier()->applyCoupon($order, 'RACE'));
            $this->reselectMethods($order);
        }
        $secondOrder = $this->reloadOrder($second);
        $secondDraft = $this->findCreation($secondOrder);
        self::assertNotNull($secondDraft);
        self::assertSame(0, $secondOrder->getPromotionCoupon()?->getUsed(), 'Loaded before the other placement.');

        // Another request placed the first draft with the coupon after this one was loaded.
        $this->connection()->executeStatement("UPDATE sylius_promotion_coupon SET used = 1 WHERE code = 'RACE'");
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'new', checkout_state = 'completed', number = 'RACE-A' WHERE id = ?", [$first]);

        $result = $this->checkout()->complete($secondDraft, false, false);

        self::assertSame(CompletionResult::CHANGED, $result->status);
        self::assertSame(OrderInterface::STATE_CART, $secondOrder->getState());
        self::assertSame(1, $this->fetchInt("SELECT used FROM sylius_promotion_coupon WHERE code = 'RACE'"));
        self::assertNull($secondOrder->getPromotionCoupon(), 'The used-up coupon is taken off the draft.');
    }

    public function testADraftPlacedByAConcurrentRequestIsNotPlacedTwice(): void
    {
        $orderId = $this->prepareDraftReadyToComplete('double.submit@example.com');
        $order = $this->reloadOrder($orderId);
        $creation = $this->findCreation($order);
        self::assertNotNull($creation);
        // The other request committed first.
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'new', number = 'CONCURRENT' WHERE id = ?", [$orderId]);

        $result = $this->checkout()->complete($creation, true, false);

        self::assertSame(CompletionResult::NOT_A_DRAFT, $result->status);
        self::assertNull($creation->getCompletedAt());
        self::assertSame('CONCURRENT', $this->connection()->fetchOne('SELECT number FROM sylius_order WHERE id = ?', [$orderId]));
    }

    public function testDeletionAndPurgeNeverDeleteAPlacedOrder(): void
    {
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('race@example.com')));
        $creation = $this->findCreation($this->reloadOrder($orderId));
        self::assertNotNull($creation);
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'new' WHERE id = ?", [$orderId]);

        /** @var DraftRemoverInterface $remover */
        $remover = self::getContainer()->get('tmonier_admin_order.draft_remover');
        self::assertFalse($remover->remove($creation));
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM sylius_order WHERE id = ?', [$orderId]));
    }

    public function testTheCoreExpiredCartsCommandKeepsTheDrafts(): void
    {
        $draftId = $this->orderIdOf($this->createDraft($this->createCustomer('weekend@example.com')));

        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = self::getContainer()->get('sylius.factory.order');
        $shopCart = $orderFactory->createNew();
        $shopCart->setChannel($this->channel());
        $shopCart->setCurrencyCode('USD');
        $shopCart->setLocaleCode('en_US');
        $this->entityManager()->persist($shopCart);
        $this->entityManager()->flush();
        $shopCartId = $this->idOf($shopCart);

        $this->connection()->executeStatement('UPDATE sylius_order SET updated_at = :date WHERE id IN (:draft, :cart)', [
            'date' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
            'draft' => $draftId,
            'cart' => $shopCartId,
        ]);

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sylius:remove-expired-carts'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM sylius_order WHERE id = ?', [$shopCartId]), 'Shop carts still expire.');
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM sylius_order WHERE id = ?', [$draftId]), 'Drafts are left to tmonier:admin-order:purge-drafts.');
    }

    private function complete(int $orderId): CompletionResult
    {
        $creation = $this->findCreation($this->reloadOrder($orderId));
        self::assertNotNull($creation);

        return $this->checkout()->complete($creation, false, false);
    }

    /** The coupon changed the cart: the methods are chosen again. */
    private function reselectMethods(OrderInterface $order): void
    {
        $shipment = $order->getShipments()->first();
        self::assertNotFalse($shipment);
        $method = $shipment->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\ShippingMethodInterface::class, $method);
        $paymentMethod = $order->getLastPayment()?->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($order, [0 => $method], $paymentMethod);
        $this->entityManager()->flush();
        self::assertTrue($this->checkout()->isReadyToComplete($order));
    }

    private function createSingleUseCoupon(string $code): void
    {
        /** @var FactoryInterface<PromotionInterface> $promotionFactory */
        $promotionFactory = self::getContainer()->get('sylius.factory.promotion');
        /** @var FactoryInterface<PromotionCouponInterface> $couponFactory */
        $couponFactory = self::getContainer()->get('sylius.factory.promotion_coupon');
        /** @var FactoryInterface<PromotionActionInterface> $actionFactory */
        $actionFactory = self::getContainer()->get('sylius.factory.promotion_action');

        $promotion = $promotionFactory->createNew();
        $promotion->setCode($code . '_PROMO');
        $promotion->setName('Single use');
        $promotion->setCouponBased(true);
        $promotion->addChannel($this->channel());
        $action = $actionFactory->createNew();
        $action->setType('order_fixed_discount');
        $action->setConfiguration([(string) $this->channel()->getCode() => ['amount' => 300]]);
        $promotion->addAction($action);
        $coupon = $couponFactory->createNew();
        $coupon->setCode($code);
        $coupon->setUsageLimit(1);
        $promotion->addCoupon($coupon);

        $this->entityManager()->persist($promotion);
        $this->entityManager()->flush();
    }
}
