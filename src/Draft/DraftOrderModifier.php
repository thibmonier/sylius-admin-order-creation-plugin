<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Draft;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Promotion\Checker\Eligibility\PromotionCouponEligibilityCheckerInterface;
use Sylius\Component\Promotion\Repository\PromotionCouponRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;
use Webmozart\Assert\Assert;

final class DraftOrderModifier implements DraftOrderModifierInterface
{
    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param AdjustmentFactoryInterface<AdjustmentInterface> $adjustmentFactory
     * @param PromotionCouponRepositoryInterface<PromotionCouponInterface> $couponRepository
     */
    public function __construct(
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderModifierInterface $orderModifier,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly AdjustmentFactoryInterface $adjustmentFactory,
        private readonly PromotionCouponRepositoryInterface $couponRepository,
        private readonly PromotionCouponEligibilityCheckerInterface $couponEligibilityChecker,
    ) {
    }

    public function addVariant(OrderInterface $order, ProductVariantInterface $variant, int $quantity): void
    {
        $this->assertDraft($order);
        Assert::greaterThan($quantity, 0);

        $item = $this->orderItemFactory->createNew();
        $item->setVariant($variant);
        $this->quantityModifier->modify($item, $quantity);

        $this->orderModifier->addToOrder($order, $item);
    }

    public function updateItems(OrderInterface $order, array $changes): void
    {
        $this->assertDraft($order);

        foreach ($order->getItems()->toArray() as $item) {
            $change = $changes[(int) $item->getId()] ?? null;
            if (null === $change) {
                continue;
            }

            if ($change['quantity'] <= 0) {
                $order->removeItem($item);

                continue;
            }

            $this->quantityModifier->modify($item, $change['quantity']);
            if (array_key_exists('unitPrice', $change)) {
                Assert::isInstanceOf($item, OrderItemInterface::class);
                $this->applyUnitPrice($item, $change['unitPrice']);
            }
        }

        $this->orderProcessor->process($order);
    }

    public function removeItem(OrderInterface $order, OrderItemInterface $item): void
    {
        $this->assertDraft($order);

        $this->orderModifier->removeFromOrder($order, $item);
    }

    public function overrideUnitPrice(OrderInterface $order, OrderItemInterface $item, ?int $unitPrice): void
    {
        $this->assertDraft($order);
        Assert::true($order->hasItem($item));

        $this->applyUnitPrice($item, $unitPrice);
        $this->orderProcessor->process($order);
    }

    public function setManualDiscount(OrderInterface $order, int $amount, string $reason): void
    {
        $this->assertDraft($order);
        Assert::greaterThanEq($amount, 0);

        $order->removeAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
        if ($amount > 0) {
            $reason = trim($reason);
            Assert::notEmpty($reason, 'A manual discount needs a reason.');

            $order->addAdjustment($this->adjustmentFactory->createWithData(
                ManualDiscount::REQUEST_ADJUSTMENT_TYPE,
                mb_substr($reason, 0, ManualDiscount::REASON_MAX_LENGTH),
                -$amount,
                true,
            ));
        }

        $this->orderProcessor->process($order);
    }

    public function getManualDiscount(OrderInterface $order): ?array
    {
        $request = $order->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE)->first();
        if (false === $request) {
            return null;
        }

        return ['amount' => abs($request->getAmount()), 'reason' => (string) $request->getLabel()];
    }

    public function applyCoupon(OrderInterface $order, ?string $code): bool
    {
        $this->assertDraft($order);

        $order->setPromotionCoupon(null);
        $code = null !== $code ? trim($code) : '';
        if ('' === $code) {
            $this->orderProcessor->process($order);

            return true;
        }

        $coupon = $this->couponRepository->findOneBy(['code' => $code]);
        if (!$coupon instanceof PromotionCouponInterface || !$this->couponEligibilityChecker->isEligible($order, $coupon)) {
            $this->orderProcessor->process($order);

            return false;
        }

        $order->setPromotionCoupon($coupon);
        $this->orderProcessor->process($order);

        $promotion = $coupon->getPromotion();
        if (null !== $promotion && $order->getPromotions()->contains($promotion)) {
            return true;
        }

        $order->setPromotionCoupon(null);
        $this->orderProcessor->process($order);

        return false;
    }

    private function applyUnitPrice(OrderItemInterface $item, ?int $unitPrice): void
    {
        if (null === $unitPrice) {
            $item->setImmutable(false);

            return;
        }

        Assert::greaterThanEq($unitPrice, 0);
        $item->setUnitPrice($unitPrice);
        $item->setImmutable(true);
    }

    private function assertDraft(OrderInterface $order): void
    {
        Assert::same($order->getState(), OrderInterface::STATE_CART, 'Only a draft (cart) order can be modified.');
    }
}
