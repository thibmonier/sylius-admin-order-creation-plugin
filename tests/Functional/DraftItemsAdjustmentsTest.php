<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;

/** Items step: quantities, custom prices, manual discount, coupon, permissions and CSRF. */
final class DraftItemsAdjustmentsTest extends WebTestCase
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

    public function testAnAdministratorWithTheRoleOverridesAPriceAndGivesAManualDiscount(): void
    {
        $admin = $this->loginAdmin();
        $draft = $this->createDraft($this->createCustomer('custom.price@example.com'), $admin, 2);
        $orderId = $this->orderIdOf($draft);
        $order = $this->reloadOrder($orderId);
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        $itemField = sprintf('tmonier_admin_order_items[item_%d]', $this->idOf($item));
        $catalogPrice = $item->getUnitPrice();

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Update items', [
            $itemField . '[quantity]' => '3',
            $itemField . '[unitPrice]' => '12.50',
        ]);
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));

        $order = $this->reloadOrder($orderId);
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(3, $item->getQuantity());
        self::assertSame(1250, $item->getUnitPrice());
        self::assertTrue($item->isImmutable(), 'A custom price is kept by the order processor.');
        self::assertSame($catalogPrice, $item->getOriginalUnitPrice());

        $this->client->followRedirect();
        self::assertSelectorExists('[data-test-custom-price]');
        $this->client->submitForm('Apply discount', [
            'tmonier_admin_order_manual_discount[amount]' => '5.00',
            'tmonier_admin_order_manual_discount[reason]' => 'Loyal customer',
        ], 'POST');
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));

        $order = $this->reloadOrder($orderId);
        $distributed = $order->getAdjustmentsTotalRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT);
        $manual = 0;
        foreach ($order->getItems() as $orderItem) {
            foreach ($orderItem->getUnits() as $unit) {
                foreach ($unit->getAdjustments(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
                    if (ManualDiscount::ORIGIN_CODE === $adjustment->getOriginCode()) {
                        $manual += $adjustment->getAmount();
                    }
                }
            }
        }
        self::assertSame(-500, $manual);
        self::assertLessThanOrEqual(-500, $distributed);
        $creation = $this->findCreation($order);
        self::assertNotNull($creation);
        self::assertSame('Loyal customer', $this->draftModifier()->getManualDiscount($creation)['reason'] ?? null);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-draft-manual-discount]', 'Loyal customer');
        self::assertSelectorExists('[data-test-draft-promotion="Discount"]');

        // Restoring the catalog price
        $this->client->submitForm('Update items', [
            $itemField . '[quantity]' => '3',
            $itemField . '[unitPrice]' => '',
        ]);
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);
        self::assertFalse($item->isImmutable());
        self::assertSame($catalogPrice, $item->getUnitPrice());
    }

    public function testAManualDiscountNeedsAReason(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('reason@example.com'), $admin));

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submitForm('Apply discount', ['tmonier_admin_order_manual_discount[amount]' => '5.00'], 'POST');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-test-manual-discount]', 'Please give a reason for the discount.');
        $creation = $this->findCreation($this->reloadOrder($orderId));
        self::assertNotNull($creation);
        self::assertNull($this->draftModifier()->getManualDiscount($creation));
    }

    public function testWithoutTheRoleThereIsNoCustomPriceNorManualDiscount(): void
    {
        $admin = $this->loginAdmin(withAdjustmentsRole: false);
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('no.role@example.com'), $admin));
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);

        $crawler = $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name$="[unitPrice]"]'));
        self::assertSelectorNotExists('[data-test-manual-discount]');

        // Forged requests are refused too.
        $this->client->request('POST', sprintf('/admin/order-drafts/%d/manual-discount', $orderId), [
            'tmonier_admin_order_manual_discount' => ['amount' => '5.00', 'reason' => 'Forged'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/order-drafts/%d/items/update', $orderId), [
            'tmonier_admin_order_items' => [
                sprintf('item_%d', $this->idOf($item)) => ['quantity' => '1', 'unitPrice' => '1.00'],
                '_token' => (string) $crawler->filter('#tmonier_admin_order_items__token')->attr('value'),
            ],
        ]);
        self::assertResponseStatusCodeSame(422, 'The extra field is rejected.');
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);
        self::assertFalse($item->isImmutable());
    }

    public function testAQuantityOfZeroAndTheRemoveButtonRemoveItems(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('remove@example.com'), $admin));
        $this->draftModifier()->addVariant($this->reloadOrder($orderId), $this->variant(1), 1);
        $this->entityManager()->flush();
        $order = $this->reloadOrder($orderId);
        self::assertCount(2, $order->getItems());
        [$first, $second] = array_values($order->getItems()->toArray());

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submitForm('Update items', [
            sprintf('tmonier_admin_order_items[item_%d][quantity]', $this->idOf($first)) => '0',
        ]);
        self::assertCount(1, $this->reloadOrder($orderId)->getItems());

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter(sprintf('[data-test-remove-item="%s"]', (string) $second->getVariant()?->getCode()))->form());
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));
        self::assertCount(0, $this->reloadOrder($orderId)->getItems());

        $this->client->followRedirect();
        self::assertSelectorExists('[data-test-draft-no-items]');
        $this->client->request('GET', sprintf('/admin/order-drafts/%d/address', $orderId));
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId), null, 'The address step needs items.');
    }

    public function testActionsAreCsrfProtected(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('csrf@example.com'), $admin));
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);

        $this->client->request('POST', sprintf('/admin/order-drafts/%d/items/%d/remove', $orderId, $this->idOf($item)), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/order-drafts/%d/delete', $orderId));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/order-drafts/%d/coupon', $orderId), ['tmonier_admin_order_coupon' => ['code' => 'X']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-test-coupon]', 'CSRF');

        self::assertCount(1, $this->reloadOrder($orderId)->getItems());
    }

    public function testACouponIsAppliedWhenValidAndRejectedOtherwise(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('coupon@example.com'), $admin));
        $this->createCouponPromotion('TMONIER_PHONE', 300);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submitForm('Apply coupon', ['tmonier_admin_order_coupon[code]' => 'UNKNOWN'], 'POST');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="error"]', 'This coupon is not valid for this order.');
        self::assertNull($this->reloadOrder($orderId)->getPromotionCoupon());

        $this->client->submitForm('Apply coupon', ['tmonier_admin_order_coupon[code]' => 'TMONIER_PHONE'], 'POST');
        $order = $this->reloadOrder($orderId);
        self::assertSame('TMONIER_PHONE', $order->getPromotionCoupon()?->getCode());
        self::assertSame(-300, $this->promotionTotal($order, 'TMONIER_PHONE_PROMO'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-draft-coupon]', 'TMONIER_PHONE');
    }

    public function testTheDraftListShowsAndDeletesDrafts(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('listed@example.com'), $admin));

        $crawler = $this->client->request('GET', '/admin/order-drafts');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(sprintf('[data-test-draft="%d"]', $orderId), 'listed@example.com');

        $this->client->submit($crawler->filter(sprintf('[data-test-draft="%d"] [data-test-delete-draft]', $orderId))->form());
        self::assertResponseRedirects('/admin/order-drafts');
        self::assertNull($this->entityManager()->getRepository(OrderInterface::class)->find($orderId));

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        self::assertResponseStatusCodeSame(404);
    }

    private function createCouponPromotion(string $couponCode, int $amount): void
    {
        /** @var FactoryInterface<PromotionInterface> $promotionFactory */
        $promotionFactory = self::getContainer()->get('sylius.factory.promotion');
        /** @var FactoryInterface<PromotionCouponInterface> $couponFactory */
        $couponFactory = self::getContainer()->get('sylius.factory.promotion_coupon');
        /** @var FactoryInterface<PromotionActionInterface> $actionFactory */
        $actionFactory = self::getContainer()->get('sylius.factory.promotion_action');

        $promotion = $promotionFactory->createNew();
        $promotion->setCode($couponCode . '_PROMO');
        $promotion->setName('Phone coupon');
        $promotion->setCouponBased(true);
        $promotion->addChannel($this->channel());

        $action = $actionFactory->createNew();
        $action->setType('order_fixed_discount');
        $action->setConfiguration([(string) $this->channel()->getCode() => ['amount' => $amount]]);
        $promotion->addAction($action);

        $coupon = $couponFactory->createNew();
        $coupon->setCode($couponCode);
        $promotion->addCoupon($coupon);

        $this->entityManager()->persist($promotion);
        $this->entityManager()->flush();
    }

    private function promotionTotal(OrderInterface $order, string $promotionCode): int
    {
        $total = 0;
        foreach ($order->getAdjustmentsRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
            if ($promotionCode === $adjustment->getOriginCode()) {
                $total += $adjustment->getAmount();
            }
        }

        return $total;
    }
}
