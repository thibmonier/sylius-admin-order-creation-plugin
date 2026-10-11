<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Draft;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

interface DraftOrderFactoryInterface
{
    /**
     * Creates (and persists, without flushing) an empty cart for the customer, marked as an
     * admin draft. The locale must be one of the channel's locales (defaults to the channel's).
     */
    public function create(
        ChannelInterface $channel,
        CustomerInterface $customer,
        ?string $localeCode,
        ?AdminUserInterface $adminUser,
        ?OrderInterface $sourceOrder = null,
    ): AdminOrderCreationInterface;
}
