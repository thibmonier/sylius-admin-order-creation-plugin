<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Address;

use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Address\OrderAddressSourceInterface;

/**
 * Stands for the adapter of a company plugin: the "company" of a customer is whatever the test
 * registered for them, so that an address registered for another customer is a foreign one.
 */
final class FakeCompanyAddressSource implements OrderAddressSourceInterface
{
    /** @var array<int, array<int, string>> street of the company addresses, by address id, by customer id */
    private static array $streets = [];

    public static function offer(int $customerId, int $addressId, string $street): void
    {
        self::$streets[$customerId][$addressId] = $street;
    }

    public static function reset(): void
    {
        self::$streets = [];
    }

    public function getCode(): string
    {
        return 'company';
    }

    public function getLabel(): string
    {
        return 'Addresses of the company';
    }

    public function getAddresses(OrderInterface $order): array
    {
        $addresses = [];
        foreach (self::$streets[(int) $order->getCustomer()?->getId()] ?? [] as $id => $street) {
            $address = new Address();
            $address->setFirstName('Acme');
            $address->setLastName('Logistics');
            $address->setCompany('ACME');
            $address->setStreet($street);
            $address->setCity('Reykjavik');
            $address->setPostcode('101');
            $address->setCountryCode('IS');
            $addresses[$id] = $address;
        }

        return $addresses;
    }
}
