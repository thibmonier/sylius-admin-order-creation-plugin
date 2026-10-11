<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Discount;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Distributor\ProportionalIntegerDistributor;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\OrderBuilder;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscountProcessor;

final class ManualDiscountProcessorTest extends TestCase
{
    private ManualDiscountProcessor $processor;

    /** @var list<string|null> */
    private array $translatedLocales = [];

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string {
            $this->translatedLocales[] = $locale;

            return 'Discount';
        });

        $this->processor = new ManualDiscountProcessor(OrderBuilder::adjustmentFactory(), new ProportionalIntegerDistributor(), $translator);
    }

    public function testOrdersWithoutRequestAreUntouched(): void
    {
        $order = OrderBuilder::cart([1000 => 2]);

        $this->processor->process($order);

        self::assertSame(2000, $order->getTotal());
        self::assertCount(0, $order->getAdjustmentsRecursively());
    }

    public function testTheDiscountIsDistributedProportionallyToTheItemsAndUnits(): void
    {
        $order = OrderBuilder::cart([1000 => 2, 3000 => 1], 'fr_FR');
        $this->request($order, 1000, 'Late delivery');

        $this->processor->process($order);

        self::assertSame(4000, $order->getTotal());
        $items = array_values($order->getItems()->toArray());
        self::assertSame([-200, -200], $this->unitDiscounts($items[0]));
        self::assertSame([-600], $this->unitDiscounts($items[1]));
        self::assertSame(['fr_FR'], $this->translatedLocales, 'The label is in the language of the order.');

        $adjustment = $order->getAdjustmentsRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT)->first();
        self::assertNotFalse($adjustment);
        self::assertSame('Discount', $adjustment->getLabel(), 'The internal reason is not shown to the customer.');
        self::assertSame(ManualDiscount::ORIGIN_CODE, $adjustment->getOriginCode());
    }

    public function testProcessingTwiceDoesNotDoubleTheDiscount(): void
    {
        $order = OrderBuilder::cart([1000 => 3]);
        $this->request($order, 100, 'Gesture');

        $this->processor->process($order);
        $this->processor->process($order);

        self::assertSame(2900, $order->getTotal());
        self::assertSame([-34, -33, -33], $this->unitDiscounts($order->getItems()->first() ?: throw new \LogicException()));
    }

    public function testTheDiscountIsCappedToTheItemsTotal(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $this->request($order, 5000, 'Free');

        $this->processor->process($order);

        self::assertSame(0, $order->getTotal());
    }

    public function testOtherPromotionsAreKept(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        $unit = $item->getUnits()->first();
        self::assertNotFalse($unit);
        $promotion = OrderBuilder::adjustmentFactory()->createWithData(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT, 'Promo', -100);
        $promotion->setOriginCode('SPRING');
        $unit->addAdjustment($promotion);
        $this->request($order, 450, 'Gesture');

        $this->processor->process($order);
        $this->processor->process($order);

        self::assertSame(450, $order->getTotal(), 'The discount applies to the total after promotions.');
        self::assertCount(2, $unit->getAdjustments(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT));
    }

    public function testPlacedOrdersAreNotProcessed(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $this->request($order, 100, 'Gesture');
        $order->setState(OrderInterface::STATE_NEW);

        $this->processor->process($order);

        self::assertSame(1000, $order->getTotal());
    }

    private function request(OrderInterface $order, int $amount, string $reason): void
    {
        $order->addAdjustment(OrderBuilder::adjustmentFactory()->createWithData(ManualDiscount::REQUEST_ADJUSTMENT_TYPE, $reason, -$amount, true));
    }

    /** @return list<int> */
    private function unitDiscounts(\Sylius\Component\Order\Model\OrderItemInterface $item): array
    {
        $amounts = [];
        foreach ($item->getUnits() as $unit) {
            $amounts[] = $unit->getAdjustmentsTotal(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT);
        }

        return $amounts;
    }
}
