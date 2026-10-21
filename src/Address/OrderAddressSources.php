<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Address;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;

final readonly class OrderAddressSources implements OrderAddressSourcesInterface
{
    /** @param iterable<OrderAddressSourceInterface> $sources */
    public function __construct(private iterable $sources)
    {
    }

    public function getGroups(OrderInterface $order): array
    {
        $groups = [];
        foreach ($this->sources as $source) {
            $addresses = [];
            foreach ($source->getAddresses($order) as $key => $address) {
                $addresses[$this->reference($source, $key)] = $address;
            }
            if ([] !== $addresses) {
                $groups[] = new AddressGroup($source->getCode(), $source->getLabel(), $addresses);
            }
        }

        return $groups;
    }

    public function find(OrderInterface $order, string $reference): ?AddressInterface
    {
        // A bare identifier is an address of the address book (links of version 1.0).
        if (!str_contains($reference, ':')) {
            $reference = AddressBookSource::CODE . ':' . $reference;
        }

        foreach ($this->getGroups($order) as $group) {
            if (isset($group->addresses[$reference])) {
                return $group->addresses[$reference];
            }
        }

        return null;
    }

    private function reference(OrderAddressSourceInterface $source, int|string $key): string
    {
        return $source->getCode() . ':' . $key;
    }
}
