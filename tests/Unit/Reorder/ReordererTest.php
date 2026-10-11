<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Reorder;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Locale\Model\Locale;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Reorder\Reorderer;

final class ReordererTest extends TestCase
{
    public function testItCopiesTheSoldItemsAndTheAddressesIntoANewDraft(): void
    {
        $channel = $this->channel();
        $source = $this->placedOrder($channel);
        $sold = $this->variant($channel, 'SOLD', true);
        $disabled = $this->variant($channel, 'DISABLED', false);
        $this->addItem($source, $sold, 2, 'T-shirt', 'Blue');
        $this->addItem($source, $disabled, 1, 'Cap', 'Red');

        $draft = new AdminOrderCreation();
        $draft->setOrder(new Order());
        $factory = $this->createMock(DraftOrderFactoryInterface::class);
        $factory->expects(self::once())->method('create')
            ->with($channel, $source->getCustomer(), 'fr_FR', null, $source)
            ->willReturn($draft);

        $added = [];
        $modifier = $this->createMock(DraftOrderModifierInterface::class);
        $modifier->method('addVariant')->willReturnCallback(static function (OrderInterface $order, ProductVariantInterface $variant, int $quantity) use (&$added): void {
            $added[(string) $variant->getCode()] = $quantity;
        });

        $result = (new Reorderer($factory, $modifier))->reorder($source, null);

        self::assertSame($draft, $result->draft);
        self::assertSame(['SOLD' => 2], $added);
        self::assertSame(['Cap Red'], $result->skippedItems);
        $newOrder = $draft->getOrder();
        self::assertNotNull($newOrder);
        self::assertSame('1 Main Street', $newOrder->getShippingAddress()?->getStreet());
        self::assertNotSame($source->getShippingAddress(), $newOrder->getShippingAddress());
        self::assertNull($newOrder->getBillingAddress()?->getCustomer());
    }

    public function testOnlyPlacedOrdersWithACustomerCanBeReordered(): void
    {
        $reorderer = new Reorderer($this->createMock(DraftOrderFactoryInterface::class), $this->createMock(DraftOrderModifierInterface::class));
        $channel = $this->channel();

        $order = $this->placedOrder($channel);
        self::assertFalse($reorderer->canReorder($order), 'No items.');

        $this->addItem($order, $this->variant($channel, 'SOLD', true), 1, 'T-shirt', 'Blue');
        self::assertTrue($reorderer->canReorder($order));

        $order->setState(OrderInterface::STATE_CART);
        self::assertFalse($reorderer->canReorder($order));
    }

    public function testAnUnavailableLocaleFallsBackToTheChannelOne(): void
    {
        $channel = $this->channel();
        $source = $this->placedOrder($channel);
        $source->setLocaleCode('de_DE');
        $this->addItem($source, $this->variant($channel, 'SOLD', true), 1, 'T-shirt', 'Blue');

        $draft = new AdminOrderCreation();
        $draft->setOrder(new Order());
        $factory = $this->createMock(DraftOrderFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with($channel, self::anything(), null)->willReturn($draft);

        (new Reorderer($factory, $this->createMock(DraftOrderModifierInterface::class)))->reorder($source, null);
    }

    private function channel(): ChannelInterface
    {
        $locale = new Locale();
        $locale->setCode('fr_FR');
        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->addLocale($locale);

        return $channel;
    }

    private function placedOrder(ChannelInterface $channel): OrderInterface
    {
        $customer = new Customer();
        $customer->setEmail('ada@example.com');
        $order = new Order();
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $order->setLocaleCode('fr_FR');
        $order->setState(OrderInterface::STATE_NEW);

        foreach (['billing', 'shipping'] as $type) {
            $address = new Address();
            $address->setStreet('1 Main Street');
            $address->setCustomer($customer);
            'billing' === $type ? $order->setBillingAddress($address) : $order->setShippingAddress($address);
        }

        return $order;
    }

    private function variant(ChannelInterface $channel, string $code, bool $enabled): ProductVariantInterface
    {
        $product = new Product();
        $product->setCurrentLocale('fr_FR');
        $product->setFallbackLocale('fr_FR');
        $product->addChannel($channel);
        $variant = new ProductVariant();
        $variant->setCode($code);
        $variant->setEnabled($enabled);
        $product->addVariant($variant);
        $pricing = new ChannelPricing();
        $pricing->setChannelCode('WEB');
        $pricing->setPrice(1000);
        $variant->addChannelPricing($pricing);

        return $variant;
    }

    private function addItem(OrderInterface $order, ProductVariantInterface $variant, int $quantity, string $productName, string $variantName): void
    {
        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setProductName($productName);
        $item->setVariantName($variantName);
        for ($i = 0; $i < $quantity; ++$i) {
            new OrderItemUnit($item);
        }
        $order->addItem($item);
    }
}
