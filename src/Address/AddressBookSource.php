<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Address;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/** The addresses of the address book of the customer of the order. */
final class AddressBookSource implements OrderAddressSourceInterface
{
    public const CODE = 'address_book';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'tmonier_admin_order.ui.address_book';
    }

    public function getAddresses(OrderInterface $order): array
    {
        $customer = $order->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            return [];
        }

        $addresses = [];
        foreach ($customer->getAddresses() as $address) {
            $id = $address->getId();
            if (\is_int($id)) {
                $addresses[$id] = $address;
            }
        }

        return $addresses;
    }
}
