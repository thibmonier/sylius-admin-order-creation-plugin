<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** Last step options (e-mails), checkout guards, reorder and the "created by" information. */
final class CompletionAndReorderTest extends WebTestCase
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

    public function testThePaymentLinkIsSentInsteadOfTheConfirmation(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('pay.later@example.com', $admin);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Place order', [
            'tmonier_admin_order_complete[sendConfirmationEmail]' => false,
            'tmonier_admin_order_complete[sendPaymentLink]' => true,
        ]);
        self::assertResponseRedirects(sprintf('/admin/orders/%d', $orderId));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'pay.later@example.com');
        $order = $this->reloadOrder($orderId);
        self::assertStringContainsString(sprintf('Payment of your order %s', (string) $order->getNumber()), (string) $email->getSubject());
        self::assertStringContainsString(sprintf('/order/%s', (string) $order->getTokenValue()), (string) $email->getHtmlBody(), 'The link opens the shop order page, where the customer pays.');

        $creation = $this->findCreation($order);
        self::assertNotNull($creation);
        self::assertFalse($creation->isConfirmationEmailSent());
        self::assertNotNull($creation->getPaymentLinkSentAt());
        self::assertSame(OrderPaymentStates::STATE_AWAITING_PAYMENT, $order->getPaymentState());

        $this->client->followRedirect();
        self::assertSelectorExists('[data-test-payment-link-sent]');

        // The shop page of the link works without being logged in.
        $this->client->request('GET', sprintf('/en_US/order/%s', (string) $order->getTokenValue()));
        self::assertResponseIsSuccessful();
    }

    public function testNoEmailIsSentWhenBothOptionsAreUnchecked(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('quiet@example.com', $admin);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        $this->client->submitForm('Place order', [
            'tmonier_admin_order_complete[sendConfirmationEmail]' => false,
        ]);

        self::assertResponseRedirects(sprintf('/admin/orders/%d', $orderId));
        self::assertEmailCount(0);
        self::assertSame(OrderInterface::STATE_NEW, $this->reloadOrder($orderId)->getState());
    }

    public function testTheOrderCannotBePlacedWhenTheStockIsInsufficient(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->prepareDraftReadyToComplete('no.stock@example.com', $admin);
        $item = $this->reloadOrder($orderId)->getItems()->first();
        self::assertNotFalse($item);
        $variant = $item->getVariant();
        self::assertNotNull($variant);
        $variant->setOnHand(1);
        $this->entityManager()->flush();

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-checkout-violations]');

        $this->client->submitForm('Place order');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrderInterface::STATE_CART, $this->reloadOrder($orderId)->getState());
    }

    public function testTheStepsCannotBeSkipped(): void
    {
        $admin = $this->loginAdmin();
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('skip@example.com'), $admin));

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/summary', $orderId));
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/address', $orderId));

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/shipping-payment', $orderId));
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/address', $orderId));
    }

    public function testAPlacedOrderIsNoLongerADraft(): void
    {
        $admin = $this->loginAdmin();
        $order = $this->placeAdminOrder($this->createCustomer('placed@example.com'), $admin);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $this->idOf($order)));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOrderIsReorderedIntoANewDraft(): void
    {
        $admin = $this->loginAdmin();
        $customer = $this->createCustomer('again@example.com', withAccount: true);
        $source = $this->placeAdminOrder($customer, $admin);
        $sourceId = $this->idOf($source);
        $sourceNumber = (string) $source->getNumber();
        $sourceQuantities = [];
        foreach ($source->getItems() as $item) {
            $sourceQuantities[(string) $item->getVariant()?->getCode()] = $item->getQuantity();
        }

        $crawler = $this->client->request('GET', sprintf('/admin/orders/%d', $sourceId));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-created-by]', 'Back office, by John Doe');
        $this->client->submit($crawler->filter('[data-test-reorder]')->form());

        $draft = $this->lastCreation();
        $draftOrderId = $this->orderIdOf($draft);
        self::assertNotSame($sourceId, $draftOrderId);
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $draftOrderId));

        $draftOrder = $this->reloadOrder($draftOrderId);
        $creation = $this->findCreation($draftOrder);
        self::assertNotNull($creation);
        self::assertSame($sourceId, $creation->getSourceOrder()?->getId());
        self::assertSame(OrderInterface::STATE_CART, $draftOrder->getState());
        self::assertSame($customer->getId(), $draftOrder->getCustomer()?->getId());
        $quantities = [];
        foreach ($draftOrder->getItems() as $item) {
            $quantities[(string) $item->getVariant()?->getCode()] = $item->getQuantity();
        }
        self::assertSame($sourceQuantities, $quantities);
        self::assertSame('1 Main Street', $draftOrder->getShippingAddress()?->getStreet());
        self::assertNotSame($source->getShippingAddress()?->getId(), $draftOrder->getShippingAddress()->getId(), 'Addresses are copied.');

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="success"]', 'A new draft has been created from this order.');

        $this->client->request('GET', sprintf('/admin/orders/%d', $sourceId));
        $this->client->request('POST', sprintf('/admin/orders/%d/reorder', $sourceId), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        // Placed, the new order shows its origin.
        $draftOrder = $this->reloadOrder($draftOrderId);
        $creation = $this->findCreation($draftOrder);
        self::assertNotNull($creation);
        $this->checkout()->address($draftOrder);
        $shipment = $draftOrder->getShipments()->first();
        self::assertNotFalse($shipment);
        $shippingMethod = $shipment->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\ShippingMethodInterface::class, $shippingMethod);
        $paymentMethod = $draftOrder->getLastPayment()?->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\PaymentMethodInterface::class, $paymentMethod);
        $this->checkout()->selectShippingAndPayment($draftOrder, [0 => $shippingMethod], $paymentMethod);
        $this->entityManager()->flush();
        self::assertTrue($this->checkout()->complete($creation, false, false)->isPlaced());

        $this->client->request('GET', sprintf('/admin/orders/%d', $draftOrderId));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-reorder-of]', $sourceNumber);
        self::assertSelectorExists(sprintf('[data-test-reorder-of] a[href="/admin/orders/%d"]', $sourceId));
    }

    public function testTheMenuLinksToTheDrafts(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/admin/orders/');

        self::assertSelectorExists('a[href="/admin/order-drafts"]');
    }
}
