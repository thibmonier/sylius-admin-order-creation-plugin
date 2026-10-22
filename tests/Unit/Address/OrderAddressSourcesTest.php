<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Address;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Address\Double\StaticAddressSource;
use Tmonier\SyliusAdminOrderCreationPlugin\Address\AddressBookSource;
use Tmonier\SyliusAdminOrderCreationPlugin\Address\OrderAddressSources;

final class OrderAddressSourcesTest extends TestCase
{
    public function testGroupsFollowTheOrderOfTheSourcesAndSkipEmptyOnes(): void
    {
        $sources = new OrderAddressSources([
            new StaticAddressSource('company', 'Company', [12 => $this->address('1 Company Street')]),
            new StaticAddressSource('empty', 'Nothing', []),
            new StaticAddressSource('address_book', 'Book', ['a' => $this->address('2 Book Street')]),
        ]);

        $groups = $sources->getGroups(new Order());

        self::assertSame(['company', 'address_book'], array_map(static fn (\Tmonier\SyliusAdminOrderCreationPlugin\Address\AddressGroup $group): string => $group->code, $groups));
        self::assertSame(['company:12'], array_keys($groups[0]->addresses));
        self::assertSame(['address_book:a'], array_keys($groups[1]->addresses));
    }

    public function testNoSourceMeansNoGroup(): void
    {
        self::assertSame([], (new OrderAddressSources([]))->getGroups(new Order()));
    }

    public function testAReferenceIsResolvedInsideItsOwnSourceOnly(): void
    {
        $company = $this->address('1 Company Street');
        $book = $this->address('2 Book Street');
        $sources = new OrderAddressSources([
            new StaticAddressSource('company', 'Company', [12 => $company]),
            new StaticAddressSource('address_book', 'Book', [12 => $book]),
        ]);

        self::assertSame($company, $sources->find(new Order(), 'company:12'));
        self::assertSame($book, $sources->find(new Order(), 'address_book:12'));
    }

    public function testAnAddressThatNoSourceOffersIsRefused(): void
    {
        $sources = new OrderAddressSources([
            new StaticAddressSource('company', 'Company', [12 => $this->address('1 Company Street')]),
        ]);

        self::assertNull($sources->find(new Order(), 'company:13'));
        self::assertNull($sources->find(new Order(), 'other_company:12'));
        self::assertNull($sources->find(new Order(), ''));
        self::assertNull($sources->find(new Order(), ':'));
        self::assertNull($sources->find(new Order(), '12'), 'A bare identifier only targets the address book.');
    }

    public function testABareIdentifierTargetsTheAddressBook(): void
    {
        $book = $this->address('2 Book Street');
        $sources = new OrderAddressSources([new StaticAddressSource('address_book', 'Book', [7 => $book])]);

        self::assertSame($book, $sources->find(new Order(), '7'));
    }

    public function testTheAddressBookOffersOnlyTheAddressesOfTheCustomerOfTheOrder(): void
    {
        $mine = $this->address('1 Mine Street', 5);
        $customer = new Customer();
        $customer->addAddress($mine);
        $other = new Customer();
        $other->addAddress($this->address('9 Other Street', 6));
        $order = new Order();
        $order->setCustomer($customer);

        $source = new AddressBookSource();

        self::assertSame([5 => $mine], $source->getAddresses($order));
        self::assertSame([], $source->getAddresses(new Order()), 'A draft without customer has no address book.');
    }

    private function address(string $street, ?int $id = null): AddressInterface
    {
        $address = new Address();
        $address->setStreet($street);
        if (null !== $id) {
            (new \ReflectionProperty(Address::class, 'id'))->setValue($address, $id);
        }

        return $address;
    }
}
