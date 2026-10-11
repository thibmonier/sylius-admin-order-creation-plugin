<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Draft;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\InMemoryFactory;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactory;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

final class DraftOrderFactoryTest extends TestCase
{
    public function testItCreatesACartLikeTheShopAndMarksItAsAnAdminDraft(): void
    {
        $customer = new Customer();
        $address = new Address();
        $address->setStreet('12 Analytical Street');
        $customer->addAddress($address);
        $customer->setDefaultAddress($address);
        $admin = new AdminUser();
        $admin->setUsername('desk');

        $entityManager = $this->createMock(ObjectManager::class);
        $entityManager->expects(self::exactly(2))->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $draft = $this->factory($entityManager)->create($this->channel(), $customer, null, $admin);
        $order = $draft->getOrder();

        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertSame(OrderInterface::STATE_CART, $order->getState());
        self::assertSame('USD', $order->getCurrencyCode());
        self::assertSame('en_US', $order->getLocaleCode(), 'The default locale of the channel.');
        self::assertSame($customer, $order->getCustomer());
        self::assertTrue($order->isCreatedByGuest(), 'Never resumed as the shop cart of the customer.');
        self::assertSame('12 Analytical Street', $order->getBillingAddress()?->getStreet());
        self::assertNotSame($address, $order->getBillingAddress());
        self::assertNull($order->getBillingAddress()->getCustomer());
        self::assertSame($admin, $draft->getAdminUser());
        self::assertTrue($draft->isDraft());
    }

    public function testTheLocaleMustBelongToTheChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory($this->createMock(ObjectManager::class))->create($this->channel(), new Customer(), 'pl_PL', null);
    }

    private function factory(ObjectManager $entityManager): DraftOrderFactory
    {
        return new DraftOrderFactory(
            new InMemoryFactory(OrderInterface::class, static fn (): OrderInterface => new Order()),
            new InMemoryFactory(AdminOrderCreationInterface::class, static fn (): AdminOrderCreationInterface => new AdminOrderCreation()),
            $entityManager,
        );
    }

    private function channel(): ChannelInterface
    {
        $currency = new Currency();
        $currency->setCode('USD');
        $english = new Locale();
        $english->setCode('en_US');
        $french = new Locale();
        $french->setCode('fr_FR');

        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($english);
        $channel->addLocale($english);
        $channel->addLocale($french);

        return $channel;
    }
}
