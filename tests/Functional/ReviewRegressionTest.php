<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Regression tests of the code review: checkout reset, empty orders, stale totals, permissions, addresses. */
final class ReviewRegressionTest extends WebTestCase
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

    public function testEmptyingADraftAtTheSummaryNeitherPlacesItNorKeepsTheChosenMethods(): void
    {
        $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('emptied@example.com');
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submitForm('Update items', [sprintf('tmonier_admin_order_items[item_%d][quantity]', $this->idOf($item)) => '0']);

        $order = $this->reloadOrder($orderId);
        self::assertCount(0, $order->getItems());
        self::assertNotSame(OrderCheckoutStates::STATE_PAYMENT_SELECTED, $order->getCheckoutState(), 'The checkout went back to the addresses (then the core skipped the steps an empty cart does not need).');
        self::assertCount(0, $order->getShipments());

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->request('POST', sprintf('/admin/order-drafts/%d/summary', $orderId), ['tmonier_admin_order_complete' => []]);
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));
        self::assertSame(OrderInterface::STATE_CART, $this->reloadOrder($orderId)->getState());
    }

    public function testAddingAnItemAfterTheMethodsRequiresChoosingThemAgain(): void
    {
        $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('changed@example.com');

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->addItem($this->variant(1), 1);

        self::assertSame(OrderCheckoutStates::STATE_ADDRESSED, $this->reloadOrder($orderId)->getCheckoutState());
        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/shipping-payment', $orderId));
    }

    public function testARequestThatChangesNothingKeepsTheChosenMethods(): void
    {
        $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('noop@example.com');

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submitForm('Update items');
        self::assertSame(OrderCheckoutStates::STATE_PAYMENT_SELECTED, $this->reloadOrder($orderId)->getCheckoutState());

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submitForm('Apply coupon', ['tmonier_admin_order_coupon[code]' => 'DOES_NOT_EXIST'], 'POST');
        self::assertSame(OrderCheckoutStates::STATE_PAYMENT_SELECTED, $this->reloadOrder($orderId)->getCheckoutState(), 'A rejected coupon changes nothing.');

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        self::assertResponseIsSuccessful();
    }

    public function testAnAdministratorIsWarnedWhenSomethingFailsAfterThePlacement(): void
    {
        $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('listener.failure@example.com');
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener('tmonier_admin_order.order_created', static function (): never {
            throw new \RuntimeException('ERP unreachable');
        });

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        $this->client->submitForm('Place order', ['tmonier_admin_order_complete[sendConfirmationEmail]' => false]);

        self::assertResponseRedirects(sprintf('/admin/orders/%d', $orderId));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="success"]', 'has been placed');
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="warning"]', 'an error occurred afterwards');
        self::assertSame(OrderInterface::STATE_NEW, $this->reloadOrder($orderId)->getState());
    }

    public function testAPriceChangedSinceTheSummaryStopsThePlacement(): void
    {
        $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('stale@example.com');
        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        $form = $this->client->getCrawler()->selectButton('Place order')->form();
        $totalShown = $this->reloadOrder($orderId)->getTotal();

        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);
        $variant = $item->getVariant();
        self::assertNotNull($variant);
        $pricing = $variant->getChannelPricingForChannel($this->channel());
        self::assertInstanceOf(ChannelPricingInterface::class, $pricing);
        $pricing->setPrice((int) $pricing->getPrice() + 1000);
        $this->entityManager()->flush();

        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/summary', $orderId));
        $order = $this->reloadOrder($orderId);
        self::assertSame(OrderInterface::STATE_CART, $order->getState());
        self::assertNotSame($totalShown, $order->getTotal(), 'The draft is recalculated.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="warning"]', 'The total of the order changed');

        $this->client->submitForm('Place order');
        self::assertResponseRedirects(sprintf('/admin/orders/%d', $orderId));
    }

    public function testAProductThatCannotBeSoldIsRefusedWithoutError(): void
    {
        $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('unsellable@example.com')));
        $disabled = $this->variant(2);
        $disabled->setEnabled(false);
        $withoutPrice = $this->variant(3);
        $pricing = $withoutPrice->getChannelPricingForChannel($this->channel());
        self::assertNotNull($pricing);
        $withoutPrice->removeChannelPricing($pricing);
        $this->entityManager()->flush();

        foreach ([$disabled, $withoutPrice] as $variant) {
            $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
            $this->addItem($variant, 1);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[data-test-add-item]', 'This product cannot be sold in the channel of the order');
        }
        self::assertCount(1, $this->reloadOrder($orderId)->getItems());
    }

    public function testAnAdministratorWithoutTheRoleCanOnlyRemoveExistingAdjustments(): void
    {
        $draft = $this->createDraft($this->createCustomer('removal@example.com'));
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        $this->draftModifier()->overrideUnitPrice($order, $item, 500);
        $this->draftModifier()->setManualDiscount($draft, 100, 'Approved by the manager');
        $this->entityManager()->flush();
        $orderId = $this->orderIdOf($draft);
        $itemId = $this->idOf($item);
        $this->loginAdmin(withAdjustmentsRole: false);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        self::assertSelectorExists('[data-test-remove-discount]');
        self::assertSelectorNotExists('[data-test-apply-discount]');

        $this->client->request('POST', sprintf('/admin/order-drafts/%d/manual-discount', $orderId), [
            'tmonier_admin_order_manual_discount' => [
                'amount' => '50.00',
                'reason' => 'Forged',
                '_token' => (string) $this->client->getCrawler()->filter('#tmonier_admin_order_manual_discount__token')->attr('value'),
            ],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->submit($this->client->getCrawler()->filter('[data-test-remove-discount]')->form());
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));
        $creation = $this->findCreation($this->reloadOrder($orderId));
        self::assertNotNull($creation);
        self::assertNull($this->draftModifier()->getManualDiscount($creation));

        $this->client->followRedirect();
        $this->client->submitForm('Update items', [sprintf('tmonier_admin_order_items[item_%d][resetPrice]', $itemId) => true]);
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);
        self::assertFalse($item->isImmutable());
    }

    public function testTheAddressStepEditsTheOrderAddressesInPlace(): void
    {
        $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('addresses@example.com', withAddress: true)));

        $counts = [];
        foreach (['1 Main Street', '2 Other Street', '3 Last Street'] as $street) {
            $this->client->request('GET', sprintf('/admin/order-drafts/%d/address', $orderId));
            $this->client->submitForm('Next: Shipping & payment', $this->addressFormData('tmonier_admin_order_address[billingAddress]', $street));
            self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/shipping-payment', $orderId));
            $counts[] = $this->fetchInt('SELECT COUNT(*) FROM sylius_address');
        }

        self::assertSame($counts[0], $counts[2], 'No orphan address row.');
        $order = $this->reloadOrder($orderId);
        self::assertSame('3 Last Street', $order->getBillingAddress()?->getStreet());
        self::assertSame('3 Last Street', $order->getShippingAddress()?->getStreet());
        self::assertNotSame($order->getBillingAddress(), $order->getShippingAddress());
    }

    public function testThePlacedOrderShowsTheManualDiscountReasonToAdministratorsOnly(): void
    {
        $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('reason@example.com');
        $creation = $this->findCreation($this->reloadOrder($orderId));
        self::assertNotNull($creation);
        $this->draftModifier()->setManualDiscount($creation, 200, 'Customer threatened chargeback');
        $this->entityManager()->flush();
        $order = $this->reloadOrder($orderId);
        $creation = $this->findCreation($order);
        self::assertNotNull($creation);
        $this->checkout()->address($order);
        $this->selectFirstMethods($order);
        self::assertTrue($this->checkout()->complete($creation, false, false)->isPlaced());

        $this->client->request('GET', sprintf('/admin/orders/%d', $orderId));
        self::assertSelectorTextContains('[data-test-manual-discount-reason]', 'Customer threatened chargeback');

        $token = (string) $this->reloadOrder($orderId)->getTokenValue();
        foreach ([sprintf('/api/v2/shop/orders/%s', $token), sprintf('/api/v2/shop/orders/%s/adjustments', $token)] as $uri) {
            $this->client->request('GET', $uri, [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('chargeback', (string) $this->client->getResponse()->getContent(), $uri);
        }
    }

    private function selectFirstMethods(OrderInterface $order): void
    {
        $shipment = $order->getShipments()->first();
        self::assertNotFalse($shipment);
        $method = $shipment->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\ShippingMethodInterface::class, $method);
        $paymentMethod = $order->getLastPayment()?->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($order, [0 => $method], $paymentMethod);
        $this->entityManager()->flush();
    }
}
