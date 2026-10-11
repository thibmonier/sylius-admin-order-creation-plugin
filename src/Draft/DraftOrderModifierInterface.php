<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Draft;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * Changes the content of a draft order. Every method runs the order processor (promotions,
 * shipping, taxes, payment) afterwards; none of them flushes. When the draft had already gone
 * past the addresses, a change sends it back to the "addressed" checkout state, so the shipping
 * and payment methods (and the summary) must be confirmed again.
 */
interface DraftOrderModifierInterface
{
    public function addVariant(OrderInterface $order, ProductVariantInterface $variant, int $quantity): void;

    /**
     * Applies several item changes then processes the order once.
     *
     * @param array<int, array{quantity: int, unitPrice?: int|null}> $changes indexed by order item id;
     *        a quantity of 0 removes the item, a "unitPrice" key overrides the unit price (null
     *        restores the catalog price)
     */
    public function updateItems(OrderInterface $order, array $changes): void;

    public function removeItem(OrderInterface $order, OrderItemInterface $item): void;

    /** A null price restores the channel price of the variant. */
    public function overrideUnitPrice(OrderInterface $order, OrderItemInterface $item, ?int $unitPrice): void;

    /** An amount of 0 removes the manual discount. The reason is stored on the draft, not on the order. */
    public function setManualDiscount(AdminOrderCreationInterface $draft, int $amount, string $reason): void;

    /** @return array{amount: int, reason: string}|null */
    public function getManualDiscount(AdminOrderCreationInterface $draft): ?array;

    /**
     * Removes the custom prices and the manual discount of a draft when the corresponding
     * feature has been disabled in the configuration. Returns true when the draft changed.
     */
    public function enforceFeatureSwitches(AdminOrderCreationInterface $draft): bool;

    /**
     * Applies the coupon, or removes the current one when the code is null. Returns false (and
     * leaves the order without coupon) when the code is unknown or the coupon does not apply.
     */
    public function applyCoupon(OrderInterface $order, ?string $code): bool;
}
