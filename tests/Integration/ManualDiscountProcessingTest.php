<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Integration;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional\AdminOrderTestTrait;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/** The manual discount and custom prices go through the real order processor of Sylius. */
final class ManualDiscountProcessingTest extends WebTestCase
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

    public function testTheDiscountIsSpreadOverTheUnitsBeforeTheTaxesAndKeptByEveryRecalculation(): void
    {
        $draft = $this->draftWithTwoItems();
        $order = $this->orderOf($draft);
        $totalBefore = $order->getTotal();
        $taxBefore = $order->getTaxTotal();
        self::assertGreaterThan(0, $taxBefore, 'The test tax (10%, excluded from prices) applies.');

        $this->draftModifier()->setManualDiscount($draft, 1000, 'Late delivery');
        $this->processor()->process($order);
        $this->processor()->process($order);

        self::assertSame(-1000, $this->manualDiscountTotal($order));
        $taxDifference = $taxBefore - $order->getTaxTotal();
        self::assertEqualsWithDelta(100, $taxDifference, 2, 'Taxes are computed on the discounted units.');
        self::assertSame($totalBefore - 1000 - $taxDifference, $order->getTotal());
        foreach ($order->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                self::assertGreaterThanOrEqual(0, $unit->getTotal());
            }
        }
        self::assertSame(['amount' => 1000, 'reason' => 'Late delivery'], $this->draftModifier()->getManualDiscount($draft));

        // The request is a neutral adjustment without the reason: it does not count twice and does not leak.
        $requests = $order->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
        self::assertCount(1, $requests);
        $request = $requests->first();
        self::assertNotFalse($request);
        self::assertTrue($request->isNeutral());
        foreach ($order->getAdjustmentsRecursively() as $adjustment) {
            self::assertStringNotContainsString('Late delivery', (string) $adjustment->getLabel());
        }

        $this->draftModifier()->setManualDiscount($draft, 0, '');
        self::assertSame(0, $this->manualDiscountTotal($order));
        self::assertSame($totalBefore, $order->getTotal());
        self::assertNull($this->draftModifier()->getManualDiscount($draft));
    }

    public function testTheDiscountNeverExceedsTheItemsTotalBeforeTaxes(): void
    {
        $draft = $this->draftWithTwoItems();
        $order = $this->orderOf($draft);
        $subtotal = $order->getItemsSubtotal();

        $this->draftModifier()->setManualDiscount($draft, $subtotal + 10000, 'Gift');

        self::assertSame(-$subtotal, $this->manualDiscountTotal($order));
        self::assertSame(0, $order->getItemsTotal(), 'No tax on free items.');
    }

    public function testACustomPriceSurvivesRecalculationUntilItIsReset(): void
    {
        $order = $this->orderOf($this->draftWithTwoItems());
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        $catalogPrice = $item->getUnitPrice();

        $this->draftModifier()->overrideUnitPrice($order, $item, 999);
        $this->processor()->process($order);
        self::assertSame(999, $item->getUnitPrice());
        self::assertSame($catalogPrice, $item->getOriginalUnitPrice());

        $this->draftModifier()->overrideUnitPrice($order, $item, null);
        self::assertSame($catalogPrice, $item->getUnitPrice());
    }

    public function testAPlacedOrderIsNoLongerRecalculated(): void
    {
        $order = $this->placeAdminOrder($this->createCustomer('placed.discount@example.com'));
        $total = $order->getTotal();

        $this->processor()->process($order);

        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
        self::assertSame($total, $order->getTotal());
        $creation = $this->findCreation($order);
        self::assertNotNull($creation);
        $this->expectException(\InvalidArgumentException::class);
        $this->draftModifier()->setManualDiscount($creation, 100, 'Too late');
    }

    /** Two items, with an address of the test zone so that the test tax applies. */
    private function draftWithTwoItems(): AdminOrderCreationInterface
    {
        $draft = $this->createDraft($this->createCustomer(uniqid('discount', true) . '@example.com'), null, 2);
        $order = $this->orderOf($draft);
        $order->setShippingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $order->setBillingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $this->draftModifier()->addVariant($order, $this->variant(1), 3);
        $this->entityManager()->flush();

        return $draft;
    }

    private function orderOf(AdminOrderCreationInterface $draft): OrderInterface
    {
        $order = $draft->getOrder();
        self::assertNotNull($order);

        return $order;
    }

    private function manualDiscountTotal(OrderInterface $order): int
    {
        $total = 0;
        foreach ($order->getAdjustmentsRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
            $total += ManualDiscount::ORIGIN_CODE === $adjustment->getOriginCode() ? $adjustment->getAmount() : 0;
        }

        return $total;
    }

    private function processor(): OrderProcessorInterface
    {
        /** @var OrderProcessorInterface $processor */
        $processor = self::getContainer()->get('sylius.order_processing.order_processor');

        return $processor;
    }
}
