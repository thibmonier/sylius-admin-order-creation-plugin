<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Address;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;

interface OrderAddressSourcesInterface
{
    /** @return list<AddressGroup> the groups that have at least one address, in the order of the sources */
    public function getGroups(OrderInterface $order): array;

    /** The address behind a "source:key" reference, only if a source offers it for this order. */
    public function find(OrderInterface $order, string $reference): ?AddressInterface;
}
