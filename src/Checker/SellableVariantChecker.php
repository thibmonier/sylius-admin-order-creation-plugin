<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checker;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/** A variant can be added to a draft when it is enabled, its product is enabled and sold in the channel, with a price there. */
final class SellableVariantChecker
{
    public function isSellable(ProductVariantInterface $variant, ChannelInterface $channel): bool
    {
        $product = $variant->getProduct();

        return $variant->isEnabled()
            && $product instanceof ProductInterface
            && $product->isEnabled()
            && $product->hasChannel($channel)
            && null !== $variant->getChannelPricingForChannel($channel)?->getPrice()
        ;
    }
}
