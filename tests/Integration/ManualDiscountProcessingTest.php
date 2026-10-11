<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Integration;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional\AdminOrderTestTrait;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;

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

    public function testTheDiscountIsSpreadOverTheUnitsAndKeptByEveryRecalculation(): void
    {
        $order = $this->draftWithTwoItems();
        $totalBefore = $order->getTotal();

        $this->draftModifier()->setManualDiscount($order, 1000, 'Late delivery');
        $this->processor()->process($order);
        $this->processor()->process($order);

        self::assertSame(-1000, $this->manualDiscountTotal($order));
        self::assertSame($totalBefore - 1000, $order->getTotal(), 'The fixture taxes are included in prices: only the discount changes the total.');
        foreach ($order->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                self::assertGreaterThanOrEqual(0, $unit->getTotal());
            }
        }
        self::assertSame(['amount' => 1000, 'reason' => 'Late delivery'], $this->draftModifier()->getManualDiscount($order));

        // The request is a neutral adjustment: it does not count twice.
        $requests = $order->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
        self::assertCount(1, $requests);
        $request = $requests->first();
        self::assertNotFalse($request);
        self::assertTrue($request->isNeutral());

        $this->draftModifier()->setManualDiscount($order, 0, '');
        self::assertSame(0, $this->manualDiscountTotal($order));
        self::assertSame($totalBefore, $order->getTotal());
        self::assertNull($this->draftModifier()->getManualDiscount($order));
    }

    public function testTheDiscountNeverExceedsTheItemsTotal(): void
    {
        $order = $this->draftWithTwoItems();
        $itemsTotal = $order->getItemsTotal();

        $this->draftModifier()->setManualDiscount($order, $itemsTotal + 10000, 'Gift');

        self::assertSame(-$itemsTotal, $this->manualDiscountTotal($order));
        self::assertSame(0, $order->getItemsTotal());
    }

    public function testACustomPriceSurvivesRecalculationUntilItIsReset(): void
    {
        $order = $this->draftWithTwoItems();
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
        $this->expectException(\InvalidArgumentException::class);
        $this->draftModifier()->setManualDiscount($order, 100, 'Too late');
    }

    private function draftWithTwoItems(): OrderInterface
    {
        $draft = $this->createDraft($this->createCustomer(uniqid('discount', true) . '@example.com'), null, 2);
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $this->draftModifier()->addVariant($order, $this->variant(1), 3);
        $this->entityManager()->flush();

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
