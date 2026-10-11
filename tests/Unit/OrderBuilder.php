<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit;

use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Order\Factory\AdjustmentFactory;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;

/** Builds real Sylius order models for the unit tests. */
final class OrderBuilder
{
    /** @param array<int, int> $items unit price => quantity */
    public static function cart(array $items, string $localeCode = 'en_US'): OrderInterface
    {
        $order = new Order();
        $order->setLocaleCode($localeCode);
        $order->setCurrencyCode('USD');
        foreach ($items as $unitPrice => $quantity) {
            $order->addItem(self::item($unitPrice, $quantity));
        }

        return $order;
    }

    public static function item(int $unitPrice, int $quantity): OrderItemInterface
    {
        $item = new OrderItem();
        $item->setUnitPrice($unitPrice);
        for ($i = 0; $i < $quantity; ++$i) {
            new OrderItemUnit($item);
        }

        return $item;
    }

    /** @return AdjustmentFactoryInterface<AdjustmentInterface> */
    public static function adjustmentFactory(): AdjustmentFactoryInterface
    {
        return new AdjustmentFactory(new InMemoryFactory(AdjustmentInterface::class, static fn (): AdjustmentInterface => new Adjustment()));
    }
}
