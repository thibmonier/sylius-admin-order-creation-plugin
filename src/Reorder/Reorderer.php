<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Reorder;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checker\SellableVariantChecker;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Webmozart\Assert\Assert;

final class Reorderer implements ReordererInterface
{
    public function __construct(
        private readonly DraftOrderFactoryInterface $draftOrderFactory,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly SellableVariantChecker $sellableVariantChecker = new SellableVariantChecker(),
    ) {
    }

    public function canReorder(OrderInterface $order): bool
    {
        return OrderInterface::STATE_CART !== $order->getState() &&
            $order->getCustomer() instanceof CustomerInterface &&
            $order->getChannel() instanceof ChannelInterface &&
            !$order->getItems()->isEmpty()
        ;
    }

    public function reorder(OrderInterface $order, ?AdminUserInterface $adminUser): ReorderResult
    {
        Assert::true($this->canReorder($order), 'This order cannot be reordered.');

        /** @var ChannelInterface $channel */
        $channel = $order->getChannel();
        /** @var CustomerInterface $customer */
        $customer = $order->getCustomer();

        $localeCode = $order->getLocaleCode();
        if (!$channel->getLocales()->exists(static fn ($key, $locale): bool => $locale->getCode() === $localeCode)) {
            $localeCode = null;
        }

        $draft = $this->draftOrderFactory->create($channel, $customer, $localeCode, $adminUser, $order);
        $newOrder = $draft->getOrder();
        Assert::notNull($newOrder);

        foreach (['billing' => $order->getBillingAddress(), 'shipping' => $order->getShippingAddress()] as $type => $address) {
            if (null === $address) {
                continue;
            }

            $copy = clone $address;
            $copy->setCustomer(null);
            'billing' === $type ? $newOrder->setBillingAddress($copy) : $newOrder->setShippingAddress($copy);
        }

        $skipped = [];
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            if (!$variant instanceof ProductVariantInterface || !$this->sellableVariantChecker->isSellable($variant, $channel)) {
                $skipped[] = trim(sprintf('%s %s', (string) $item->getProductName(), (string) $item->getVariantName()));

                continue;
            }

            $this->draftOrderModifier->addVariant($newOrder, $variant, $item->getQuantity());
        }

        return new ReorderResult($draft, $skipped);
    }

}
