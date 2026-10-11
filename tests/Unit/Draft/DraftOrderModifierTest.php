<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Draft;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\Promotion;
use Sylius\Component\Core\Model\PromotionCoupon;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Promotion\Checker\Eligibility\PromotionCouponEligibilityCheckerInterface;
use Sylius\Component\Promotion\Repository\PromotionCouponRepositoryInterface;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\InMemoryFactory;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\OrderBuilder;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifier;

final class DraftOrderModifierTest extends TestCase
{
    private OrderProcessorInterface&MockObject $processor;

    /** @var PromotionCouponRepositoryInterface<PromotionCouponInterface>&MockObject */
    private PromotionCouponRepositoryInterface&MockObject $couponRepository;

    private PromotionCouponEligibilityCheckerInterface&MockObject $couponEligibilityChecker;

    private DraftOrderModifier $modifier;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(OrderProcessorInterface::class);
        $this->couponRepository = $this->createMock(PromotionCouponRepositoryInterface::class);
        $this->couponEligibilityChecker = $this->createMock(PromotionCouponEligibilityCheckerInterface::class);

        $quantityModifier = $this->createMock(OrderItemQuantityModifierInterface::class);
        $quantityModifier->method('modify')->willReturnCallback(static function (OrderItemInterface $item, int $quantity): void {
            while ($item->getQuantity() < $quantity) {
                new \Sylius\Component\Core\Model\OrderItemUnit($item);
            }
            while ($item->getQuantity() > $quantity) {
                $unit = $item->getUnits()->last();
                \assert(false !== $unit);
                $item->removeUnit($unit);
            }
        });

        $this->modifier = new DraftOrderModifier(
            new InMemoryFactory(OrderItemInterface::class, static fn (): OrderItemInterface => new OrderItem()),
            $this->createMock(OrderModifierInterface::class),
            $quantityModifier,
            $this->processor,
            OrderBuilder::adjustmentFactory(),
            $this->couponRepository,
            $this->couponEligibilityChecker,
        );
    }

    public function testACustomPriceMakesTheItemImmutableAndNullRestoresTheCatalogPrice(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $item);
        $this->processor->expects(self::exactly(2))->method('process')->with($order);

        $this->modifier->overrideUnitPrice($order, $item, 750);
        self::assertSame(750, $item->getUnitPrice());
        self::assertTrue($item->isImmutable());

        $this->modifier->overrideUnitPrice($order, $item, null);
        self::assertFalse($item->isImmutable());
    }

    public function testItemsAreUpdatedThenTheOrderIsProcessedOnce(): void
    {
        $order = OrderBuilder::cart([1000 => 1, 2000 => 1]);
        [$first, $second] = array_values($order->getItems()->toArray());
        $this->setId($first, 1);
        $this->setId($second, 2);
        $this->processor->expects(self::once())->method('process');

        $this->modifier->updateItems($order, [
            1 => ['quantity' => 3, 'unitPrice' => 900],
            2 => ['quantity' => 0],
        ]);

        self::assertCount(1, $order->getItems());
        self::assertSame(3, $first->getQuantity());
        self::assertSame(900, $first->getUnitPrice());
        self::assertTrue($first->isImmutable());
    }

    public function testTheManualDiscountIsANeutralRequestWithItsReason(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);

        $this->modifier->setManualDiscount($order, 300, '  Late delivery ');
        $this->modifier->setManualDiscount($order, 250, 'Late delivery');

        $requests = $order->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
        self::assertCount(1, $requests, 'A new discount replaces the previous one.');
        $request = $requests->first();
        self::assertNotFalse($request);
        self::assertTrue($request->isNeutral());
        self::assertSame(['amount' => 250, 'reason' => 'Late delivery'], $this->modifier->getManualDiscount($order));
        self::assertSame(1000, $order->getTotal(), 'The request itself does not change the total.');

        $this->modifier->setManualDiscount($order, 0, '');
        self::assertNull($this->modifier->getManualDiscount($order));
    }

    public function testAManualDiscountNeedsAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->modifier->setManualDiscount(OrderBuilder::cart([1000 => 1]), 100, ' ');
    }

    public function testOnlyDraftsCanBeModified(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $order->setState(OrderInterface::STATE_NEW);

        $this->expectException(\InvalidArgumentException::class);
        $this->modifier->setManualDiscount($order, 100, 'Too late');
    }

    public function testAnUnknownOrIneligibleCouponIsRefused(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $coupon = new PromotionCoupon();
        $this->couponRepository->method('findOneBy')->willReturnMap([
            [['code' => 'UNKNOWN'], null, null],
            [['code' => 'EXPIRED'], null, $coupon],
        ]);
        $this->couponEligibilityChecker->method('isEligible')->willReturn(false);

        self::assertFalse($this->modifier->applyCoupon($order, 'UNKNOWN'));
        self::assertFalse($this->modifier->applyCoupon($order, 'EXPIRED'));
        self::assertNull($order->getPromotionCoupon());
    }

    public function testACouponIsKeptOnlyWhenItsPromotionApplies(): void
    {
        $order = OrderBuilder::cart([1000 => 1]);
        $promotion = new Promotion();
        $coupon = new PromotionCoupon();
        $coupon->setPromotion($promotion);
        $this->couponRepository->method('findOneBy')->willReturn($coupon);
        $this->couponEligibilityChecker->method('isEligible')->willReturn(true);

        $eligibility = new class() {
            public bool $applies = false;
        };
        $this->processor->method('process')->willReturnCallback(static function (OrderInterface $order) use ($eligibility, $promotion): void {
            if ($eligibility->applies && null !== $order->getPromotionCoupon()) {
                $order->addPromotion($promotion);
            }
        });

        self::assertFalse($this->modifier->applyCoupon($order, 'SPRING'));
        self::assertNull($order->getPromotionCoupon());

        $eligibility->applies = true;
        self::assertTrue($this->modifier->applyCoupon($order, 'SPRING'));
        self::assertSame($coupon, $order->getPromotionCoupon());

        self::assertTrue($this->modifier->applyCoupon($order, null), 'An empty code removes the coupon.');
        self::assertNull($order->getPromotionCoupon());
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
