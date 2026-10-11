<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminOrderCreationFlowTest extends WebTestCase
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

    public function testTheOrderListAndTheCustomerPageOfferToCreateAnOrder(): void
    {
        $customer = $this->createCustomer('phone.order@example.com');
        $this->loginAdmin();

        $this->client->request('GET', '/admin/orders/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-test-create-order][href="/admin/order-drafts/new"]');

        $this->client->request('GET', sprintf('/admin/customers/%d', $this->idOf($customer)));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[data-test-create-order-for-customer][href="/admin/order-drafts/new?customerId=%d"]', $this->idOf($customer)));
    }

    public function testAnAdministratorPlacesAnOrderForANewGuestCustomer(): void
    {
        $admin = $this->loginAdmin();
        $variant = $this->variant();

        // 1. Channel and customer
        $this->client->request('GET', '/admin/order-drafts/new');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Start the order', [
            'tmonier_admin_order_start[channel]' => (string) $this->channel()->getCode(),
            'tmonier_admin_order_start[email]' => 'New.Guest@Example.com',
            'tmonier_admin_order_start[firstName]' => 'Grace',
            'tmonier_admin_order_start[lastName]' => 'Hopper',
        ]);

        $draft = $this->lastCreation();
        $orderId = $this->orderIdOf($draft);
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));
        $order = $draft->getOrder();
        self::assertNotNull($order);
        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        self::assertSame('New.Guest@Example.com', $customer->getEmail());
        self::assertNull($customer->getUser(), 'A guest customer has no shop account.');
        self::assertSame($admin->getId(), $draft->getAdminUser()?->getId());
        self::assertTrue($order->isCreatedByGuest());
        self::assertSame(OrderInterface::STATE_CART, $order->getState());

        // 2. Items
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-draft-steps] .active', 'Items');
        $this->addItem($variant, 3);
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/items', $orderId));
        $this->client->followRedirect();
        self::assertSelectorExists(sprintf('[data-test-draft-item="%s"]', (string) $variant->getCode()));
        $order = $this->reloadOrder($orderId);
        self::assertSame(3, $order->getTotalQuantity());
        self::assertSelectorTextContains('[data-test-draft-total]', $this->formatted($order->getTotal()));

        // 3. Addresses
        $this->client->clickLink('Next: Addresses');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Next: Shipping & payment', $this->addressFormData('tmonier_admin_order_address[billingAddress]'));
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/shipping-payment', $orderId));
        $order = $this->reloadOrder($orderId);
        self::assertSame(OrderCheckoutStates::STATE_ADDRESSED, $order->getCheckoutState());
        self::assertSame('1 Main Street', $order->getShippingAddress()?->getStreet(), 'The billing address is used for shipping.');

        // 4. Shipping and payment
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Next: Summary')->form();
        $form->setValues([
            'tmonier_admin_order_shipping_payment[shipment_0]' => self::EXPRESS_SHIPPING_METHOD,
            'tmonier_admin_order_shipping_payment[paymentMethod]' => self::PAYMENT_METHOD,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects(sprintf('/admin/order-drafts/%d/summary', $orderId));
        $order = $this->reloadOrder($orderId);
        self::assertSame(OrderCheckoutStates::STATE_PAYMENT_SELECTED, $order->getCheckoutState());
        $shipment = $order->getShipments()->first();
        self::assertNotFalse($shipment);
        self::assertSame(self::EXPRESS_SHIPPING_METHOD, $shipment->getMethod()?->getCode());

        // 5. Summary and completion
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-summary-shipping-method]', 'Tmonier express');
        self::assertCheckboxChecked('tmonier_admin_order_complete[sendConfirmationEmail]');
        $this->client->submitForm('Place order', [
            'tmonier_admin_order_complete[notes]' => 'Delivery before noon.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/orders/%d', $orderId));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'New.Guest@Example.com');

        $order = $this->reloadOrder($orderId);
        $draft = $this->findCreation($order);
        self::assertNotNull($draft);

        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $order->getCheckoutState());
        self::assertSame(OrderPaymentStates::STATE_AWAITING_PAYMENT, $order->getPaymentState());
        self::assertNotNull($order->getNumber());
        self::assertNotNull($order->getTokenValue());
        self::assertSame('Delivery before noon.', $order->getNotes());
        self::assertSame(3, $this->reloadVariant($variant)->getOnHold(), 'The inventory is held like for a shop order.');
        self::assertNotNull($draft->getCompletedAt());
        self::assertTrue($draft->isConfirmationEmailSent());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message]', sprintf('The order %s has been placed.', (string) $order->getNumber()));
        self::assertSelectorTextContains('[data-test-created-by]', 'Back office, by John Doe');
        self::assertStringNotContainsString('tmonier_admin_order.', $crawler->filter('body')->text());
    }

    public function testTheCustomerPagePreselectsTheCustomerAndTheirDefaultAddress(): void
    {
        $customer = $this->createCustomer('ada@example.com', withAccount: true, withAddress: true);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/order-drafts/new?customerId=%d', $this->idOf($customer)));
        self::assertResponseIsSuccessful();
        self::assertSame((string) $this->idOf($customer), $crawler->filter('#tmonier_admin_order_start_customer option[selected]')->attr('value'));

        $this->client->submitForm('Start the order', [
            'tmonier_admin_order_start[channel]' => (string) $this->channel()->getCode(),
        ]);
        $draft = $this->lastCreation();
        $order = $draft->getOrder();
        self::assertNotNull($order);
        self::assertSame($customer->getId(), $order->getCustomer()?->getId());
        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        self::assertSame('12 Analytical Street', $order->getBillingAddress()?->getStreet());

        // The draft is not the customer's shop cart.
        /** @var OrderRepositoryInterface<OrderInterface> $orderRepository */
        $orderRepository = self::getContainer()->get('sylius.repository.order');
        $this->draftModifier()->addVariant($order, $this->variant(), 1);
        $this->entityManager()->flush();
        self::assertNull($orderRepository->findLatestNotEmptyCartByChannelAndCustomer($this->channel(), $customer));

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/address', $this->orderIdOf($draft)));
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('tmonier_admin_order_address[billingAddress][street]', '12 Analytical Street');
        self::assertSelectorExists('[data-test-address-book]');
    }

    public function testAnExistingEmailReusesTheCustomer(): void
    {
        $customer = $this->createCustomer('known@example.com');
        $this->loginAdmin();

        $this->client->request('GET', '/admin/order-drafts/new');
        $this->client->submitForm('Start the order', [
            'tmonier_admin_order_start[channel]' => (string) $this->channel()->getCode(),
            'tmonier_admin_order_start[email]' => 'KNOWN@example.com',
        ]);

        self::assertSame($customer->getId(), $this->lastCreation()->getOrder()?->getCustomer()?->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="info"]', 'A customer already uses this e-mail');
    }

    public function testTheStartFormRequiresACustomer(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/admin/order-drafts/new');
        $this->client->submitForm('Start the order', [
            'tmonier_admin_order_start[channel]' => (string) $this->channel()->getCode(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-test-start-form]', 'Choose an existing customer or enter the e-mail of a new one.');
    }

    private function formatted(int $amount): string
    {
        return number_format($amount / 100, 2);
    }
}
