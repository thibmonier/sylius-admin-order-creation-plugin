<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Address;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * A list of known addresses offered at the address step of a draft order (the customer's
 * address book, the addresses of the customer's company...). Implementations are optional
 * extension points: tag the service with "tmonier_admin_order.address_source" (autoconfigured)
 * and, to order the groups, give the tag a priority (the higher, the earlier).
 */
interface OrderAddressSourceInterface
{
    /** Stable identifier of the source (letters, digits, "_" and "-"), used in the "code:key" references. */
    public function getCode(): string;

    /** Translation key (domain "messages") of the label of the group, or a plain label. */
    public function getLabel(): string;

    /**
     * Only the addresses the customer of the order, or the company they belong to, may use: the
     * result is the whitelist against which a submitted reference is checked. The addresses are
     * only read (their data is copied into the order), they are never attached to it.
     *
     * @return array<int|string, AddressInterface> addresses indexed by their identifier in the source
     */
    public function getAddresses(OrderInterface $order): array;
}
