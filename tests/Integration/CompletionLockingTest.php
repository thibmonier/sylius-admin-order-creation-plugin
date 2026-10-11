<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Integration;

use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional\AdminOrderTestTrait;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\CompletionResult;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;

/**
 * Real concurrency, outside the usual rolled-back transaction: another process places an order
 * with the same coupon while the draft is being placed (it holds the coupon row lock, then
 * commits). Under REPEATABLE READ the completion must not have read anything before it got the
 * locks, otherwise it works on a snapshot older than the other commit. The rows created here are
 * committed and deleted in tearDown().
 */
final class CompletionLockingTest extends WebTestCase
{
    use AdminOrderTestTrait;

    /** @var array{foreignOrder?: int} */
    private array $restore = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        if (!str_contains((string) $this->connection()->getDatabasePlatform()::class, 'MySQL') && !str_contains((string) $this->connection()->getDatabasePlatform()::class, 'MariaDB')) {
            self::markTestSkipped('The concurrent process uses MySQL / MariaDB locking statements.');
        }

        $this->setUpWorld(endFixturePromotions: false);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testACouponWithRoomLeftIsUsedByBothOrders(): void
    {
        $couponId = $this->createCoupon('LOCK_ROOM', usageLimit: 2, perCustomerUsageLimit: null);
        $draft = $this->readyDraftWithCoupon('lock.room@example.com', 'LOCK_ROOM');

        $other = $this->startConcurrentUse($couponId, 'plain');
        $result = $this->checkout()->complete($draft, false, false);
        $other->wait();

        self::assertSame(CompletionResult::PLACED, $result->status, $other->getErrorOutput());
        self::assertSame(2, $this->fetchInt('SELECT used FROM sylius_promotion_coupon WHERE id = ?', [$couponId]));
    }

    public function testACouponUsedMeanwhileByTheSameCustomerIsDetected(): void
    {
        $couponId = $this->createCoupon('LOCK_CUSTOMER', usageLimit: null, perCustomerUsageLimit: 1);
        $draft = $this->readyDraftWithCoupon('lock.customer@example.com', 'LOCK_CUSTOMER');
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        $other = $this->startConcurrentUse($couponId, 'customer', [(string) $this->idOfChannel(), (string) $this->idOf($customer), 'LOCK-' . bin2hex(random_bytes(4))]);
        $result = $this->checkout()->complete($draft, false, false);
        $other->wait();
        $this->restore['foreignOrder'] = $this->fetchInt('SELECT id FROM sylius_order WHERE customer_id = ? AND state = ? ORDER BY id DESC LIMIT 1', [$customer->getId(), 'new']);

        self::assertSame(CompletionResult::CHANGED, $result->status, $other->getErrorOutput());
        self::assertSame(OrderInterface::STATE_CART, $this->fetchOrderState($this->idOf($order)));
        self::assertSame(1, $this->fetchInt('SELECT used FROM sylius_promotion_coupon WHERE id = ?', [$couponId]));
    }

    private function idOfChannel(): int
    {
        $id = $this->channel()->getId();
        self::assertIsInt($id);

        return $id;
    }

    /** @param list<string> $arguments */
    private function startConcurrentUse(int $couponId, string $mode, array $arguments = []): Process
    {
        $params = $this->connection()->getParams();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $params['host'] ?? '127.0.0.1', $params['port'] ?? 3306, $params['dbname'] ?? '');
        $process = new Process(array_merge([\PHP_BINARY, __DIR__ . '/Resources/concurrent_coupon_use.php', $dsn, (string) ($params['user'] ?? 'root'), (string) ($params['password'] ?? ''), (string) $couponId, $mode], $arguments));
        $process->setTimeout(30);
        $process->start();
        $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked'));

        return $process;
    }

    private function readyDraftWithCoupon(string $email, string $couponCode): AdminOrderCreation
    {
        $orderId = $this->prepareDraftReadyToComplete($email);
        $order = $this->reloadOrder($orderId);
        self::assertTrue($this->draftModifier()->applyCoupon($order, $couponCode));
        $shipment = $order->getShipments()->first();
        self::assertNotFalse($shipment);
        $shippingMethod = $shipment->getMethod();
        self::assertInstanceOf(ShippingMethodInterface::class, $shippingMethod);
        $paymentMethod = $order->getLastPayment()?->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($order, [0 => $shippingMethod], $paymentMethod);
        $this->entityManager()->flush();

        $draft = $this->findCreation($order);
        self::assertInstanceOf(AdminOrderCreation::class, $draft);

        return $draft;
    }

    private function createCoupon(string $code, ?int $usageLimit, ?int $perCustomerUsageLimit): int
    {
        /** @var FactoryInterface<PromotionInterface> $promotionFactory */
        $promotionFactory = self::getContainer()->get('sylius.factory.promotion');
        /** @var FactoryInterface<PromotionCouponInterface> $couponFactory */
        $couponFactory = self::getContainer()->get('sylius.factory.promotion_coupon');
        /** @var FactoryInterface<PromotionActionInterface> $actionFactory */
        $actionFactory = self::getContainer()->get('sylius.factory.promotion_action');

        $promotion = $promotionFactory->createNew();
        $promotion->setCode($code . '_PROMO');
        $promotion->setName('Locking test');
        $promotion->setCouponBased(true);
        $promotion->addChannel($this->channel());
        $action = $actionFactory->createNew();
        $action->setType('order_fixed_discount');
        $action->setConfiguration([(string) $this->channel()->getCode() => ['amount' => 300]]);
        $promotion->addAction($action);
        $coupon = $couponFactory->createNew();
        $coupon->setCode($code);
        $coupon->setUsageLimit($usageLimit);
        $coupon->setPerCustomerUsageLimit($perCustomerUsageLimit);
        $promotion->addCoupon($coupon);
        $this->entityManager()->persist($promotion);
        $this->entityManager()->flush();

        $id = $coupon->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function fetchOrderState(int $orderId): string
    {
        $state = $this->connection()->fetchOne('SELECT state FROM sylius_order WHERE id = ?', [$orderId]);
        self::assertIsString($state);

        return $state;
    }

    private function cleanUp(): void
    {
        $connection = $this->connection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if (!$this->entityManager()->isOpen()) {
            /** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
            $doctrine = self::getContainer()->get('doctrine');
            $doctrine->resetManager();
        }
        $em = $this->entityManager();
        $em->clear();
        // Leftovers of an interrupted run.
        $this->countryCreated = $this->countryCreated || 0 === $this->fetchInt("SELECT COUNT(*) FROM sylius_address WHERE country_code = 'IS' AND customer_id IS NOT NULL");

        if (isset($this->restore['foreignOrder'])) {
            $connection->executeStatement('DELETE FROM sylius_order WHERE id = ?', [$this->restore['foreignOrder']]);
        }
        foreach ($em->getRepository(CustomerInterface::class)->findBy(['email' => ['lock.room@example.com', 'lock.customer@example.com']]) as $customer) {
            foreach ($em->getRepository(OrderInterface::class)->findBy(['customer' => $customer]) as $order) {
                foreach ($em->getRepository(AdminOrderCreation::class)->findBy(['order' => $order]) as $creation) {
                    $em->remove($creation);
                }
                $em->remove($order);
            }
            $em->flush();
            $em->remove($customer);
        }
        foreach ($em->getRepository(PromotionInterface::class)->findBy(['code' => ['LOCK_ROOM_PROMO', 'LOCK_CUSTOMER_PROMO']]) as $promotion) {
            $em->remove($promotion);
        }
        $em->flush();

        foreach ($this->variantOriginals as $variant) {
            $connection->executeStatement('UPDATE sylius_product_variant SET tax_category_id = ?, tracked = ?, on_hand = ?, on_hold = ? WHERE id = ?', [$variant['tax_category_id'], $variant['tracked'], $variant['on_hand'], $variant['on_hold'], $variant['id']]);
        }
        $em->clear();
        foreach ([TaxRateInterface::class => 'tmonier_standard_10', TaxCategoryInterface::class => self::TAX_CATEGORY, ShippingMethodInterface::class => [self::SHIPPING_METHOD, self::EXPRESS_SHIPPING_METHOD], PaymentMethodInterface::class => self::PAYMENT_METHOD, ZoneInterface::class => 'TMONIER_ZONE'] as $class => $codes) {
            foreach ($em->getRepository($class)->findBy(['code' => $codes]) as $entity) {
                if ($entity instanceof PaymentMethodInterface && null !== $entity->getGatewayConfig()) {
                    $em->remove($entity->getGatewayConfig());
                }
                $em->remove($entity);
            }
            $em->flush();
        }

        if ($this->countryCreated) {
            $country = $em->getRepository(\Sylius\Component\Addressing\Model\CountryInterface::class)->findOneBy(['code' => self::COUNTRY]);
            if (null !== $country) {
                foreach ($em->getRepository(\Sylius\Component\Core\Model\ChannelInterface::class)->findAll() as $channel) {
                    $channel->removeCountry($country);
                }
                $em->remove($country);
                $em->flush();
            }
        }
    }
}
