<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Integration;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional\AdminOrderTestTrait;
use Tmonier\SyliusAdminOrderCreationPlugin\Event\AdminOrderCreatedEvent;

/**
 * The same order (items, coupon, address, shipping and payment methods) is placed once through
 * the Sylius shop API and once with the plugin: both must end up identical.
 */
final class ShopParityTest extends WebTestCase
{
    use AdminOrderTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testAnOrderPlacedFromTheBackOfficeEqualsTheSameShopOrder(): void
    {
        $first = $this->variant(0);
        $second = $this->variant(1);
        $this->createCouponPromotion('PARITY', 500);
        $firstCode = (string) $first->getCode();
        $secondCode = (string) $second->getCode();

        $shopOrder = $this->placeShopOrder('parity.shop@example.com', [$firstCode => 2, $secondCode => 1], 'PARITY');

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $events = [];
        $dispatcher->addListener(AdminOrderCreatedEvent::NAME, static function (AdminOrderCreatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $adminOrder = $this->placeAdminOrderLike('parity.admin@example.com', [$firstCode => 2, $secondCode => 1], 'PARITY');

        foreach (['getItemsTotal', 'getTaxTotal', 'getShippingTotal', 'getOrderPromotionTotal', 'getTotal', 'getState', 'getCheckoutState', 'getPaymentState', 'getShippingState', 'getCurrencyCode', 'getLocaleCode', 'isCreatedByGuest'] as $getter) {
            self::assertSame($shopOrder->$getter(), $adminOrder->$getter(), $getter);
        }
        self::assertNotSame(0, $adminOrder->getTaxTotal(), 'The comparison covers taxes.');
        $couponDiscount = 0;
        foreach ($adminOrder->getAdjustmentsRecursively(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
            $couponDiscount += 'PARITY_PROMO' === $adjustment->getOriginCode() ? $adjustment->getAmount() : 0;
        }
        self::assertSame(-500, $couponDiscount, 'The comparison covers promotions.');
        self::assertSame($this->promotionCodes($shopOrder), $this->promotionCodes($adminOrder));
        self::assertSame($this->itemSnapshot($shopOrder), $this->itemSnapshot($adminOrder));

        self::assertNotNull($adminOrder->getNumber());
        self::assertNotSame($shopOrder->getNumber(), $adminOrder->getNumber());
        self::assertNotNull($adminOrder->getTokenValue());
        self::assertNotNull($adminOrder->getCheckoutCompletedAt());

        self::assertSame($this->paymentSnapshot($shopOrder), $this->paymentSnapshot($adminOrder));
        self::assertSame($this->shipmentSnapshot($shopOrder), $this->shipmentSnapshot($adminOrder));

        self::assertSame(4, $this->reloadVariant($first)->getOnHold(), 'Both orders hold the inventory.');
        self::assertSame(2, $this->reloadVariant($second)->getOnHold());
        self::assertSame(2, $this->entityManager()->getRepository(PromotionCouponInterface::class)->findOneBy(['code' => 'PARITY'])?->getUsed(), 'Both orders use the coupon.');

        self::assertCount(1, $events);
        self::assertSame($adminOrder->getId(), $events[0]->getOrder()->getId());
        self::assertSame('sylius', $events[0]->getAdminUser()?->getUsername());
    }

    /** @param array<string, int> $quantities */
    private function placeShopOrder(string $email, array $quantities, string $couponCode): OrderInterface
    {
        $cart = $this->api('POST', '/api/v2/shop/orders', ['localeCode' => 'en_US']);
        $token = $cart['tokenValue'];
        self::assertIsString($token);

        foreach ($quantities as $code => $quantity) {
            $this->api('POST', sprintf('/api/v2/shop/orders/%s/items', $token), [
                'productVariant' => sprintf('/api/v2/shop/product-variants/%s', $code),
                'quantity' => $quantity,
            ]);
        }

        $order = $this->api('PUT', sprintf('/api/v2/shop/orders/%s', $token), [
            'email' => $email,
            'billingAddress' => [
                'firstName' => 'Grace',
                'lastName' => 'Hopper',
                'street' => '1 Main Street',
                'city' => 'London',
                'postcode' => 'NW1 6XE',
                'countryCode' => self::COUNTRY,
            ],
            'couponCode' => $couponCode,
        ]);

        self::assertIsArray($order['shipments']);
        self::assertIsArray($order['payments']);
        $shipment = $order['shipments'][0];
        $payment = $order['payments'][0];
        self::assertIsArray($shipment);
        self::assertIsArray($payment);
        self::assertIsInt($shipment['id']);
        self::assertIsInt($payment['id']);
        $this->api('PATCH', sprintf('/api/v2/shop/orders/%s/shipments/%d', $token, $shipment['id']), [
            'shippingMethod' => sprintf('/api/v2/shop/shipping-methods/%s', self::EXPRESS_SHIPPING_METHOD),
        ]);
        $this->api('PATCH', sprintf('/api/v2/shop/orders/%s/payments/%d', $token, $payment['id']), [
            'paymentMethod' => sprintf('/api/v2/shop/payment-methods/%s', self::PAYMENT_METHOD),
        ]);
        $this->api('PATCH', sprintf('/api/v2/shop/orders/%s/complete', $token), []);

        $placed = $this->entityManager()->getRepository(OrderInterface::class)->findOneBy(['tokenValue' => $token]);
        self::assertInstanceOf(OrderInterface::class, $placed);

        return $placed;
    }

    /** @param array<string, int> $quantities */
    private function placeAdminOrderLike(string $email, array $quantities, string $couponCode): OrderInterface
    {
        $admin = $this->loginAdmin();
        $draft = $this->draftFactory()->create($this->channel(), $this->createCustomer($email), 'en_US', $admin);
        $order = $draft->getOrder();
        self::assertNotNull($order);
        foreach ($quantities as $code => $quantity) {
            $variant = $this->entityManager()->getRepository(\Sylius\Component\Core\Model\ProductVariantInterface::class)->findOneBy(['code' => $code]);
            self::assertNotNull($variant);
            $this->draftModifier()->addVariant($order, $variant, $quantity);
        }
        self::assertTrue($this->draftModifier()->applyCoupon($order, $couponCode));

        $order->setBillingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $order->setShippingAddress($this->createAddress('Grace', 'Hopper', '1 Main Street'));
        $this->checkout()->address($order);

        $shippingMethod = $this->entityManager()->getRepository(ShippingMethodInterface::class)->findOneBy(['code' => self::EXPRESS_SHIPPING_METHOD]);
        $paymentMethod = $this->entityManager()->getRepository(PaymentMethodInterface::class)->findOneBy(['code' => self::PAYMENT_METHOD]);
        self::assertInstanceOf(\Sylius\Component\Core\Model\ShippingMethodInterface::class, $shippingMethod);
        self::assertInstanceOf(\Sylius\Component\Core\Model\PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($order, [0 => $shippingMethod], $paymentMethod);
        $this->entityManager()->flush();

        $result = $this->checkout()->complete($draft, true, false);
        self::assertTrue($result->confirmationEmailSent);

        return $order;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, array $body): array
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertLessThan(300, $response->getStatusCode(), sprintf('%s %s: %s', $method, $uri, (string) $response->getContent()));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function createCouponPromotion(string $couponCode, int $amount): void
    {
        /** @var FactoryInterface<PromotionInterface> $promotionFactory */
        $promotionFactory = self::getContainer()->get('sylius.factory.promotion');
        /** @var FactoryInterface<PromotionCouponInterface> $couponFactory */
        $couponFactory = self::getContainer()->get('sylius.factory.promotion_coupon');
        /** @var FactoryInterface<PromotionActionInterface> $actionFactory */
        $actionFactory = self::getContainer()->get('sylius.factory.promotion_action');

        $promotion = $promotionFactory->createNew();
        $promotion->setCode($couponCode . '_PROMO');
        $promotion->setName('Parity coupon');
        $promotion->setCouponBased(true);
        $promotion->addChannel($this->channel());

        $action = $actionFactory->createNew();
        $action->setType('order_fixed_discount');
        $action->setConfiguration([(string) $this->channel()->getCode() => ['amount' => $amount]]);
        $promotion->addAction($action);

        $coupon = $couponFactory->createNew();
        $coupon->setCode($couponCode);
        $promotion->addCoupon($coupon);

        $this->entityManager()->persist($promotion);
        $this->entityManager()->flush();
    }

    /** @return list<string> */
    private function promotionCodes(OrderInterface $order): array
    {
        $codes = array_map(static fn ($promotion): string => (string) $promotion->getCode(), $order->getPromotions()->toArray());
        sort($codes);

        return $codes;
    }

    /** @return array<string, array<string, int>> */
    private function itemSnapshot(OrderInterface $order): array
    {
        $snapshot = [];
        foreach ($order->getItems() as $item) {
            $snapshot[(string) $item->getVariant()?->getCode()] = [
                'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPrice(),
                'total' => $item->getTotal(),
                'taxTotal' => $item->getTaxTotal(),
            ];
        }
        ksort($snapshot);

        return $snapshot;
    }

    /** @return list<array<string, mixed>> */
    private function paymentSnapshot(OrderInterface $order): array
    {
        return array_values(array_map(static fn ($payment): array => [
            'state' => $payment->getState(),
            'amount' => $payment->getAmount(),
            'method' => $payment->getMethod()?->getCode(),
            'currency' => $payment->getCurrencyCode(),
        ], $order->getPayments()->toArray()));
    }

    /** @return list<array<string, mixed>> */
    private function shipmentSnapshot(OrderInterface $order): array
    {
        return array_values(array_map(static fn ($shipment): array => [
            'state' => $shipment->getState(),
            'method' => $shipment->getMethod()?->getCode(),
            'units' => $shipment->getUnits()->count(),
        ], $order->getShipments()->toArray()));
    }
}
