<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Serialises the operations on a draft (placement, deletion, purge): locks the order row for
 * the current transaction and reads its state again from the database.
 */
interface OrderRowLockInterface
{
    /** Must run inside a transaction. Returns false when the order is no longer a cart. */
    public function lockDraft(OrderInterface $order): bool;

    /**
     * Must run inside a transaction. Locks and reloads the coupon of the order and the
     * promotions with a usage limit, so that their usage counts are current while the order
     * is recalculated and placed (two drafts sharing a single-use coupon).
     */
    public function lockPromotionUsages(OrderInterface $order): void;
}
