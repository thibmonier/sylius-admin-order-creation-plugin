<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Factory\ZoneFactoryInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\Scope;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckoutInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * Tests run against the test application loaded with the Sylius fixtures. The kernel is not
 * rebooted between requests and every test runs in a transaction rolled back at the end.
 * The fixtures are random: every test creates the customers, stock and states it relies on.
 */
trait AdminOrderTestTrait
{
    /** Role required for price overrides and manual discounts in the test application. */
    private const ADJUSTMENTS_ROLE = 'ROLE_TMONIER_ORDER_ADJUSTMENTS';

    private KernelBrowser $client;

    /** @var array<int, array<string, mixed>> original stock columns of the variants changed by variant() */
    private array $variantOriginals = [];

    private bool $countryCreated = false;

    /** Codes of the deterministic test setup (the Sylius fixtures are random). */
    private const COUNTRY = 'IS';

    private const SHIPPING_METHOD = 'tmonier_courier';

    private const SHIPPING_METHOD_NAME = 'Tmonier courier';

    private const EXPRESS_SHIPPING_METHOD = 'tmonier_express';

    private const PAYMENT_METHOD = 'tmonier_invoice';

    private const TAX_CATEGORY = 'tmonier_standard';

    private function setUpClient(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
        $this->setUpWorld();
    }

    /**
     * Inside the rolled-back transaction: a country with its own zone, two shipping methods
     * and an offline payment method for the channel, a 10% tax excluded from prices, and no
     * fixture promotion running (their usage and dates are random).
     */
    private function setUpWorld(bool $endFixturePromotions = true): void
    {
        if ($endFixturePromotions) {
            $this->connection()->executeStatement('UPDATE sylius_promotion SET ends_at = :past', ['past' => '2000-01-01 00:00:00']);
        }

        $channel = $this->channel();
        $country = $this->entityManager()->getRepository(CountryInterface::class)->findOneBy(['code' => self::COUNTRY]);
        if (!$country instanceof CountryInterface) {
            /** @var FactoryInterface<CountryInterface> $countryFactory */
            $countryFactory = self::getContainer()->get('sylius.factory.country');
            $country = $countryFactory->createNew();
            $country->setCode(self::COUNTRY);
            $this->entityManager()->persist($country);
            $this->countryCreated = true;
        }
        $country->enable();
        if (!$channel->getCountries()->isEmpty()) {
            $channel->addCountry($country);
        }

        /** @var ZoneFactoryInterface<ZoneInterface> $zoneFactory */
        $zoneFactory = self::getContainer()->get('sylius.factory.zone');
        $zone = $zoneFactory->createWithMembers([self::COUNTRY]);
        $zone->setCode('TMONIER_ZONE');
        $zone->setName('Tmonier test zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $zone->setScope(Scope::ALL);
        $this->entityManager()->persist($zone);

        foreach ([self::SHIPPING_METHOD => [self::SHIPPING_METHOD_NAME, 700], self::EXPRESS_SHIPPING_METHOD => ['Tmonier express', 1500]] as $code => [$name, $amount]) {
            /** @var FactoryInterface<ShippingMethodInterface> $shippingMethodFactory */
            $shippingMethodFactory = self::getContainer()->get('sylius.factory.shipping_method');
            $shippingMethod = $shippingMethodFactory->createNew();
            $shippingMethod->setCode($code);
            $shippingMethod->setCurrentLocale('en_US');
            $shippingMethod->setFallbackLocale('en_US');
            $shippingMethod->setName($name);
            $shippingMethod->setZone($zone);
            $shippingMethod->setCalculator('flat_rate');
            $shippingMethod->setConfiguration([(string) $channel->getCode() => ['amount' => $amount]]);
            $shippingMethod->setPosition('tmonier_courier' === $code ? -100 : -99);
            $shippingMethod->addChannel($channel);
            $shippingMethod->enable();
            $this->entityManager()->persist($shippingMethod);
        }

        /** @var PaymentMethodFactoryInterface<PaymentMethodInterface> $paymentMethodFactory */
        $paymentMethodFactory = self::getContainer()->get('sylius.factory.payment_method');
        $paymentMethod = $paymentMethodFactory->createWithGateway('offline');
        $paymentMethod->getGatewayConfig()?->setGatewayName('tmonier_offline');
        $paymentMethod->setCode(self::PAYMENT_METHOD);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Tmonier invoice');
        $paymentMethod->setPosition(-100);
        $paymentMethod->addChannel($channel);
        $paymentMethod->setEnabled(true);
        $this->entityManager()->persist($paymentMethod);

        /** @var FactoryInterface<TaxCategoryInterface> $taxCategoryFactory */
        $taxCategoryFactory = self::getContainer()->get('sylius.factory.tax_category');
        $taxCategory = $taxCategoryFactory->createNew();
        $taxCategory->setCode(self::TAX_CATEGORY);
        $taxCategory->setName('Tmonier standard');
        $this->entityManager()->persist($taxCategory);

        /** @var FactoryInterface<TaxRateInterface> $taxRateFactory */
        $taxRateFactory = self::getContainer()->get('sylius.factory.tax_rate');
        $rate = $taxRateFactory->createNew();
        $rate->setCode('tmonier_standard_10');
        $rate->setName('Standard 10%');
        $rate->setAmount(0.1);
        $rate->setIncludedInPrice(false);
        $rate->setCalculator('default');
        $rate->setCategory($taxCategory);
        $rate->setZone($zone);
        $this->entityManager()->persist($rate);

        $this->entityManager()->flush();
    }

    private function taxCategory(): TaxCategoryInterface
    {
        $category = $this->entityManager()->getRepository(TaxCategoryInterface::class)->findOneBy(['code' => self::TAX_CATEGORY]);
        self::assertInstanceOf(TaxCategoryInterface::class, $category);

        return $category;
    }

    private function tearDownClient(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function loginAdmin(bool $withAdjustmentsRole = true): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        if ($withAdjustmentsRole) {
            $admin->addRole(self::ADJUSTMENTS_ROLE);
        } else {
            $admin->removeRole(self::ADJUSTMENTS_ROLE);
        }
        $this->entityManager()->flush();
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['code' => 'FASHION_WEB']);
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    /** An enabled variant sold in the channel, made tracked with enough stock for the test. */
    private function variant(int $offset = 0, int $onHand = 50): ProductVariantInterface
    {
        /** @var list<ProductVariantInterface> $variants */
        $variants = $this->entityManager()->createQueryBuilder()
            ->select('v')
            ->from(ProductVariantInterface::class, 'v')
            ->innerJoin('v.product', 'p')
            ->innerJoin('p.channels', 'c')
            ->innerJoin('v.channelPricings', 'cp', 'WITH', 'cp.channelCode = :channelCode')
            ->andWhere('c.code = :channelCode')
            ->andWhere('v.enabled = true')
            ->andWhere('p.enabled = true')
            ->andWhere('cp.price > 1000')
            ->setParameter('channelCode', 'FASHION_WEB')
            ->orderBy('v.id', 'ASC')
            ->setMaxResults($offset + 1)
            ->getQuery()
            ->getResult()
        ;
        self::assertArrayHasKey($offset, $variants, 'The fixtures contain sellable variants.');

        $variant = $variants[$offset];
        $original = $this->connection()->fetchAssociative('SELECT id, tax_category_id, tracked, on_hand, on_hold FROM sylius_product_variant WHERE id = ?', [$variant->getId()]);
        self::assertIsArray($original);
        $this->variantOriginals[$this->idOf($variant)] ??= $original;
        $variant->setTaxCategory($this->taxCategory());
        $variant->setTracked(true);
        $variant->setOnHand($onHand);
        $variant->setOnHold(0);
        $this->entityManager()->flush();

        return $variant;
    }

    private function price(ProductVariantInterface $variant): int
    {
        $price = $variant->getChannelPricingForChannel($this->channel())?->getPrice();
        self::assertIsInt($price);

        return $price;
    }

    private function createCustomer(string $email, bool $withAccount = false, bool $withAddress = false): CustomerInterface
    {
        /** @var FactoryInterface<CustomerInterface> $customerFactory */
        $customerFactory = self::getContainer()->get('sylius.factory.customer');
        $customer = $customerFactory->createNew();
        $customer->setEmail($email);
        $customer->setFirstName('Ada');
        $customer->setLastName('Lovelace');

        if ($withAccount) {
            /** @var FactoryInterface<ShopUserInterface> $userFactory */
            $userFactory = self::getContainer()->get('sylius.factory.shop_user');
            $user = $userFactory->createNew();
            $user->setPlainPassword('secret-password');
            $user->setEnabled(true);
            $customer->setUser($user);
        }

        if ($withAddress) {
            $address = $this->createAddress('Ada', 'Lovelace', '12 Analytical Street');
            $customer->addAddress($address);
            $customer->setDefaultAddress($address);
        }

        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        return $customer;
    }

    private function createAddress(string $firstName, string $lastName, string $street): AddressInterface
    {
        /** @var FactoryInterface<AddressInterface> $addressFactory */
        $addressFactory = self::getContainer()->get('sylius.factory.address');
        $address = $addressFactory->createNew();
        $address->setFirstName($firstName);
        $address->setLastName($lastName);
        $address->setStreet($street);
        $address->setCity('London');
        $address->setPostcode('NW1 6XE');
        $address->setCountryCode(self::COUNTRY);

        return $address;
    }

    /** @return array<string, string> */
    private function addressFormData(string $prefix, string $street = '1 Main Street'): array
    {
        return [
            $prefix . '[firstName]' => 'Grace',
            $prefix . '[lastName]' => 'Hopper',
            $prefix . '[street]' => $street,
            $prefix . '[city]' => 'Cambridge',
            $prefix . '[postcode]' => 'CB2 1TN',
            $prefix . '[countryCode]' => self::COUNTRY,
        ];
    }

    /** A draft with items, built with the plugin services (like the admin controllers do). */
    private function createDraft(CustomerInterface $customer, ?AdminUserInterface $admin = null, int $quantity = 2): AdminOrderCreationInterface
    {
        $draft = $this->draftFactory()->create($this->channel(), $customer, null, $admin);
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $this->draftModifier()->addVariant($order, $this->variant(), $quantity);
        $this->entityManager()->flush();

        return $draft;
    }

    /** A draft at the summary step: addresses, first shipping and payment methods. */
    private function prepareDraftReadyToComplete(string $email, ?AdminUserInterface $admin = null): int
    {
        $draft = $this->createDraft($this->createCustomer($email), $admin);
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $order->setBillingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $order->setShippingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $this->checkout()->address($order);
        $shipment = $order->getShipments()->first();
        self::assertNotFalse($shipment);
        $method = $shipment->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\ShippingMethodInterface::class, $method);
        $paymentMethod = $order->getLastPayment()?->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($order, [0 => $method], $paymentMethod);
        $this->entityManager()->flush();
        self::assertTrue($this->checkout()->isReadyToComplete($order));

        return $this->orderIdOf($draft);
    }

    /** Builds and places an order through the plugin services (address, first methods, complete). */
    private function placeAdminOrder(CustomerInterface $customer, ?AdminUserInterface $admin = null, bool $sendConfirmationEmail = false): OrderInterface
    {
        $draft = $this->createDraft($customer, $admin);
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $order->setBillingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $order->setShippingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $this->checkout()->address($order);

        $shippingMethods = [];
        foreach (array_values($order->getShipments()->toArray()) as $index => $shipment) {
            $method = $shipment->getMethod();
            self::assertInstanceOf(ShippingMethodInterface::class, $method);
            $shippingMethods[$index] = $method;
        }
        $paymentMethod = $order->getLastPayment()?->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($order, $shippingMethods, $paymentMethod);
        $this->entityManager()->flush();
        $this->checkout()->complete($draft, $sendConfirmationEmail, false);

        return $order;
    }

    /** Submits the "add a product" form of the items page currently displayed (autocomplete field). */
    private function addItem(ProductVariantInterface $variant, int $quantity): void
    {
        $form = $this->client->getCrawler()->selectButton('Add')->form();
        $form->disableValidation();
        $form->setValues([
            'tmonier_admin_order_add_item[variant]' => (string) $this->idOf($variant),
            'tmonier_admin_order_add_item[quantity]' => (string) $quantity,
        ]);
        $this->client->submit($form);
    }

    /**
     * The entity manager is reset after every request of the client: entities loaded before a
     * request must be loaded again to see its changes.
     */
    private function reloadOrder(int $orderId): OrderInterface
    {
        $order = $this->entityManager()->getRepository(OrderInterface::class)->find($orderId);
        self::assertInstanceOf(OrderInterface::class, $order);

        return $order;
    }

    private function reloadVariant(ProductVariantInterface $variant): ProductVariantInterface
    {
        $reloaded = $this->entityManager()->getRepository(ProductVariantInterface::class)->find($variant->getId());
        self::assertInstanceOf(ProductVariantInterface::class, $reloaded);

        return $reloaded;
    }

    private function findCreation(OrderInterface $order): ?AdminOrderCreationInterface
    {
        /** @var AdminOrderCreationInterface|null $creation */
        $creation = $this->entityManager()->getRepository(AdminOrderCreation::class)->findOneBy(['order' => $order]);

        return $creation;
    }

    /** The draft created by the last request (the newest record). */
    private function lastCreation(): AdminOrderCreationInterface
    {
        /** @var AdminOrderCreationInterface|null $creation */
        $creation = $this->entityManager()->getRepository(AdminOrderCreation::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($creation);

        return $creation;
    }

    private function orderIdOf(AdminOrderCreationInterface $creation): int
    {
        $id = $creation->getOrder()?->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function idOf(OrderInterface|CustomerInterface|OrderItemInterface|ProductVariantInterface $resource): int
    {
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function draftFactory(): DraftOrderFactoryInterface
    {
        /** @var DraftOrderFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_admin_order.draft.factory');

        return $factory;
    }

    private function draftModifier(): DraftOrderModifierInterface
    {
        /** @var DraftOrderModifierInterface $modifier */
        $modifier = self::getContainer()->get('tmonier_admin_order.draft.modifier');

        return $modifier;
    }

    private function checkout(): DraftOrderCheckoutInterface
    {
        /** @var DraftOrderCheckoutInterface $checkout */
        $checkout = self::getContainer()->get('tmonier_admin_order.checkout');

        return $checkout;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /** @param array<string, mixed>|list<mixed> $parameters */
    private function fetchInt(string $sql, array $parameters = []): int
    {
        $value = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
