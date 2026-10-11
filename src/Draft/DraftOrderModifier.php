<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Draft;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Promotion\Checker\Eligibility\PromotionCouponEligibilityCheckerInterface;
use Sylius\Component\Promotion\Repository\PromotionCouponRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
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
        private readonly StateMachineInterface $stateMachine,
        private readonly bool $priceOverrideAllowed = true,
        private readonly bool $manualDiscountAllowed = true,
    ) {
    }

    public function addVariant(OrderInterface $order, ProductVariantInterface $variant, int $quantity): void
    {
        $this->assertDraft($order);
        Assert::greaterThan($quantity, 0);
        $before = $this->fingerprint($order);

        $item = $this->orderItemFactory->createNew();
        $item->setVariant($variant);
        $this->quantityModifier->modify($item, $quantity);

        $this->orderModifier->addToOrder($order, $item);
        $this->invalidateCheckoutIfChanged($order, $before);
    }

    public function updateItems(OrderInterface $order, array $changes): void
    {
        $this->assertDraft($order);
        $before = $this->fingerprint($order);

        foreach ($order->getItems()->toArray() as $item) {
            $itemId = $item->getId();
            $change = is_int($itemId) ? ($changes[$itemId] ?? null) : null;
            if (null === $change) {
                continue;
            }

            if ($change['quantity'] <= 0) {
                $order->removeItem($item);

                continue;
            }

            $this->quantityModifier->modify($item, $change['quantity']);
            if (array_key_exists('unitPrice', $change)) {
                $this->applyUnitPrice($item, $change['unitPrice']);
            }
        }

        $this->process($order, $before);
    }

    public function removeItem(OrderInterface $order, OrderItemInterface $item): void
    {
        $this->assertDraft($order);
        $before = $this->fingerprint($order);

        $this->orderModifier->removeFromOrder($order, $item);
        $this->invalidateCheckoutIfChanged($order, $before);
    }

    public function overrideUnitPrice(OrderInterface $order, OrderItemInterface $item, ?int $unitPrice): void
    {
        $this->assertDraft($order);
        Assert::true($order->hasItem($item));
        $before = $this->fingerprint($order);

        $this->applyUnitPrice($item, $unitPrice);
        $this->process($order, $before);
    }

    public function setManualDiscount(AdminOrderCreationInterface $draft, int $amount, string $reason): void
    {
        $order = $draft->getOrder();
        Assert::notNull($order);
        $this->assertDraft($order);
        Assert::greaterThanEq($amount, 0);
        $before = $this->fingerprint($order);

        $order->removeAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
        $draft->setManualDiscountReason(null);
        if ($amount > 0) {
            $reason = trim($reason);
            Assert::notEmpty($reason, 'A manual discount needs a reason.');

            $order->addAdjustment($this->adjustmentFactory->createWithData(
                ManualDiscount::REQUEST_ADJUSTMENT_TYPE,
                ManualDiscount::REQUEST_LABEL,
                -$amount,
                true,
            ));
            $draft->setManualDiscountReason(mb_substr($reason, 0, ManualDiscount::REASON_MAX_LENGTH));
        }

        $this->process($order, $before);
    }

    public function getManualDiscount(AdminOrderCreationInterface $draft): ?array
    {
        $request = $draft->getOrder()?->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE)->first();
        if (null === $request || false === $request) {
            return null;
        }

        return ['amount' => abs($request->getAmount()), 'reason' => (string) $draft->getManualDiscountReason()];
    }

    public function enforceFeatureSwitches(AdminOrderCreationInterface $draft): bool
    {
        $order = $draft->getOrder();
        if (null === $order || OrderInterface::STATE_CART !== $order->getState()) {
            return false;
        }

        $before = $this->fingerprint($order);
        $changed = false;
        if (!$this->manualDiscountAllowed && null !== $this->getManualDiscount($draft)) {
            $order->removeAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
            $draft->setManualDiscountReason(null);
            $changed = true;
        }

        if (!$this->priceOverrideAllowed) {
            foreach ($order->getItems() as $item) {
                if ($item->isImmutable()) {
                    $item->setImmutable(false);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->process($order, $before);
        }

        return $changed;
    }

    public function applyCoupon(OrderInterface $order, ?string $code): bool
    {
        $this->assertDraft($order);
        $before = $this->fingerprint($order);
        $previousCoupon = $order->getPromotionCoupon();

        $code = null !== $code ? trim($code) : '';
        if ('' === $code) {
            $order->setPromotionCoupon(null);
            $this->process($order, $before);

            return true;
        }

        $coupon = $this->couponRepository->findOneBy(['code' => $code]);
        if ($coupon instanceof PromotionCouponInterface && $this->couponEligibilityChecker->isEligible($order, $coupon)) {
            $order->setPromotionCoupon($coupon);
            $this->orderProcessor->process($order);

            $promotion = $coupon->getPromotion();
            if (null !== $promotion && $order->getPromotions()->contains($promotion)) {
                $this->invalidateCheckoutIfChanged($order, $before);

                return true;
            }
        }

        // Rejected: the draft keeps its previous coupon.
        $order->setPromotionCoupon($previousCoupon);
        $this->process($order, $before);

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

    /** @param list<mixed> $before */
    private function process(OrderInterface $order, array $before): void
    {
        $this->orderProcessor->process($order);
        $this->invalidateCheckoutIfChanged($order, $before);
    }

    /**
     * What the shipping and payment selection depends on: items, prices, coupon, discounts, total.
     *
     * @return list<mixed>
     */
    private function fingerprint(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [$item->getVariant()?->getCode(), $item->getQuantity(), $item->getUnitPrice(), $item->isImmutable(), $item->getTotal()];
        }

        return [
            $items,
            $order->getPromotionCoupon()?->getCode(),
            array_map(static fn ($adjustment): int => $adjustment->getAmount(), $order->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE)->toArray()),
            $order->getTotal(),
        ];
    }

    /**
     * The shipping and payment selection depends on the content of the cart: once it changes,
     * the draft goes back to "addressed" (the core listeners recalculate it and skip the steps
     * that are no longer needed). A request that changes nothing keeps the checkout state.
     *
     * @param list<mixed> $before
     */
    private function invalidateCheckoutIfChanged(OrderInterface $order, array $before): void
    {
        if ($before === $this->fingerprint($order) || in_array($order->getCheckoutState(), [OrderCheckoutStates::STATE_CART, OrderCheckoutStates::STATE_ADDRESSED], true)) {
            return;
        }

        if ($this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS)) {
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        }
    }

    private function assertDraft(OrderInterface $order): void
    {
        Assert::same($order->getState(), OrderInterface::STATE_CART, 'Only a draft (cart) order can be modified.');
    }
}
