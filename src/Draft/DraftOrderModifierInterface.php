<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Draft;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Changes the content of a draft order. Every method runs the order processor (promotions,
 * shipping, taxes, payment) afterwards; none of them flushes.
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

    /** An amount of 0 removes the manual discount. */
    public function setManualDiscount(OrderInterface $order, int $amount, string $reason): void;

    /** @return array{amount: int, reason: string}|null */
    public function getManualDiscount(OrderInterface $order): ?array;

    /**
     * Applies the coupon, or removes the current one when the code is null. Returns false (and
     * leaves the order without coupon) when the code is unknown or the coupon does not apply.
     */
    public function applyCoupon(OrderInterface $order, ?string $code): bool;
}
