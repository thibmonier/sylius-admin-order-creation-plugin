<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Reorder;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;

interface ReordererInterface
{
    public function canReorder(OrderInterface $order): bool;

    /**
     * Creates (and persists, without flushing) a draft for the same customer, channel and
     * locale, with the items still sold in the channel at their current price, and copies of the
     * addresses. Coupons, custom prices and manual discounts are not copied.
     */
    public function reorder(OrderInterface $order, ?AdminUserInterface $adminUser): ReorderResult;
}
