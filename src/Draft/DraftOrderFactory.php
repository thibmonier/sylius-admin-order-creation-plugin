<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Draft;

use Doctrine\Persistence\ObjectManager;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Webmozart\Assert\Assert;

/**
 * Builds the cart the way the shop does (ShopBasedCartContext): channel, base currency, locale,
 * customer and the customer's default address as billing address. The order keeps the default
 * "created by guest" flag, so the shop never resumes it as the customer's cart.
 */
final class DraftOrderFactory implements DraftOrderFactoryInterface
{
    /**
     * @param FactoryInterface<OrderInterface> $orderFactory
     * @param FactoryInterface<AdminOrderCreationInterface> $creationFactory
     */
    public function __construct(
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $creationFactory,
        private readonly ObjectManager $entityManager,
    ) {
    }

    public function create(
        ChannelInterface $channel,
        CustomerInterface $customer,
        ?string $localeCode,
        ?AdminUserInterface $adminUser,
        ?OrderInterface $sourceOrder = null,
    ): AdminOrderCreationInterface {
        $currencyCode = $channel->getBaseCurrency()?->getCode();
        Assert::notNull($currencyCode, 'The channel has no base currency.');

        $localeCode ??= $channel->getDefaultLocale()?->getCode();
        Assert::notNull($localeCode, 'The channel has no default locale.');
        Assert::true(
            $channel->getLocales()->exists(static fn ($key, $locale): bool => $locale->getCode() === $localeCode),
            sprintf('The locale "%s" is not available in the channel "%s".', $localeCode, (string) $channel->getCode()),
        );

        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setCurrencyCode($currencyCode);
        $order->setLocaleCode($localeCode);
        $order->setCustomer($customer);

        $defaultAddress = $customer->getDefaultAddress();
        if (null !== $defaultAddress) {
            $address = clone $defaultAddress;
            $address->setCustomer(null);
            $order->setBillingAddress($address);
        }

        $creation = $this->creationFactory->createNew();
        $creation->setOrder($order);
        $creation->setAdminUser($adminUser);
        $creation->setSourceOrder($sourceOrder);

        $this->entityManager->persist($order);
        $this->entityManager->persist($creation);

        return $creation;
    }
}
