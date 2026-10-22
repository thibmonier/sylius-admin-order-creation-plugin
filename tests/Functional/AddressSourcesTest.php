<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Address\FakeCompanyAddressSource;

/** The address step offers the addresses of the company, then of the address book, then free input. */
final class AddressSourcesTest extends WebTestCase
{
    use AdminOrderTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        FakeCompanyAddressSource::reset();
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testTheCompanyAddressesComeBeforeTheAddressBook(): void
    {
        $customer = $this->customerWithBook('claire@acme.example');
        FakeCompanyAddressSource::offer($this->idOf($customer), 31, '3 Harbour Road');
        FakeCompanyAddressSource::offer($this->idOf($customer), 32, '4 Harbour Road');
        $orderId = $this->orderIdOf($this->createDraft($customer));

        $crawler = $this->client->request('GET', $this->url($orderId));

        self::assertResponseIsSuccessful();
        self::assertSame(['company', 'address_book'], $crawler->filter('[data-test-address-group]')->each(static fn ($group): ?string => $group->attr('data-test-address-group')));
        self::assertSame(['company:31', 'company:32', 'address_book:' . $this->defaultAddressId($customer)], $crawler->filter('[data-test-use-address]')->each(static fn ($radio): ?string => $radio->attr('value')));
        self::assertSelectorTextContains('[data-test-address-group="company"] legend', 'Addresses of the company');
        self::assertSelectorTextContains('[data-test-address-group="address_book"] legend', 'Address book');
    }

    public function testChoosingACompanyAddressPrefillsBillingAndShipping(): void
    {
        $customer = $this->customerWithBook('claire@acme.example');
        FakeCompanyAddressSource::offer($this->idOf($customer), 31, '3 Harbour Road');
        $orderId = $this->orderIdOf($this->createDraft($customer));

        $this->client->request('GET', $this->url($orderId));
        $this->client->submit($this->client->getCrawler()->selectButton('Use the selected address')->form(['address' => 'company:31']));

        self::assertResponseIsSuccessful();
        self::assertInputValueSame('tmonier_admin_order_address[billingAddress][street]', '3 Harbour Road');
        self::assertInputValueSame('tmonier_admin_order_address[shippingAddress][street]', '3 Harbour Road');
        self::assertInputValueSame('tmonier_admin_order_address[billingAddress][company]', 'ACME');
    }

    public function testTheChosenAddressCanBeEditedThenIsCopiedIntoTheOrder(): void
    {
        $customer = $this->customerWithBook('claire@acme.example');
        FakeCompanyAddressSource::offer($this->idOf($customer), 31, '3 Harbour Road');
        $orderId = $this->orderIdOf($this->createDraft($customer));
        $bookAddressId = $this->defaultAddressId($customer);

        $this->client->request('GET', $this->url($orderId, 'address_book:' . $bookAddressId));
        $this->client->submitForm('Next: Shipping & payment', ['tmonier_admin_order_address[billingAddress][street]' => '5 Edited Road']);

        $order = $this->reloadOrder($orderId);
        self::assertSame('5 Edited Road', $order->getBillingAddress()?->getStreet());
        self::assertSame('5 Edited Road', $order->getShippingAddress()?->getStreet());
        self::assertNotSame($bookAddressId, $order->getBillingAddress()->getId(), 'The order holds a copy, not the address of the book.');
        self::assertSame('12 Analytical Street', $customer->getDefaultAddress()?->getStreet(), 'The source address is not modified.');
    }

    public function testAnAddressOfAnotherCompanyIsRefusedAndTheOrderIsNotModified(): void
    {
        $claire = $this->customerWithBook('claire@acme.example');
        $other = $this->createCustomer('bob@buildco.example');
        FakeCompanyAddressSource::offer($this->idOf($claire), 31, '3 Harbour Road');
        FakeCompanyAddressSource::offer($this->idOf($other), 99, '9 Foreign Street');
        $orderId = $this->orderIdOf($this->createDraft($claire));

        $this->client->request('GET', $this->url($orderId, 'company:99'));

        self::assertResponseRedirects($this->url($orderId));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This address is not available for this customer');
        self::assertInputValueSame('tmonier_admin_order_address[billingAddress][street]', '12 Analytical Street');
        self::assertSame('12 Analytical Street', $this->reloadOrder($orderId)->getBillingAddress()?->getStreet(), 'The order is not modified.');
    }

    public function testAnAddressOfTheAddressBookOfAnotherCustomerIsRefused(): void
    {
        $claire = $this->customerWithBook('claire@acme.example');
        $other = $this->customerWithBook('bob@buildco.example');
        $draft = $this->createDraft($claire);
        $orderId = $this->orderIdOf($draft);
        $billingId = $draft->getOrder()?->getBillingAddress()?->getId();

        $this->client->request('GET', $this->url($orderId, (string) $this->defaultAddressId($other)));

        self::assertResponseRedirects($this->url($orderId));
        self::assertSame($billingId, $this->reloadOrder($orderId)->getBillingAddress()?->getId(), 'The order is not modified.');
    }

    public function testNoGroupWhenNoAddressIsKnown(): void
    {
        $orderId = $this->orderIdOf($this->createDraft($this->createCustomer('nobody@example.com')));

        $this->client->request('GET', $this->url($orderId));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-test-address-group]');
        self::assertSelectorExists('[data-test-address-form]');
    }

    public function testTheAddressBookAloneIsStillOffered(): void
    {
        $customer = $this->customerWithBook('claire@acme.example');
        $orderId = $this->orderIdOf($this->createDraft($customer));

        $crawler = $this->client->request('GET', $this->url($orderId));

        self::assertSame(['address_book'], $crawler->filter('[data-test-address-group]')->each(static fn ($group): ?string => $group->attr('data-test-address-group')));
        self::assertSelectorExists('[data-test-address-form]');
    }

    public function testTheRadioGroupsAreLabelledAndKeyboardReachable(): void
    {
        $customer = $this->customerWithBook('claire@acme.example');
        FakeCompanyAddressSource::offer($this->idOf($customer), 31, '3 Harbour Road');
        $orderId = $this->orderIdOf($this->createDraft($customer));

        $crawler = $this->client->request('GET', $this->url($orderId));

        self::assertCount(2, $crawler->filter('fieldset[data-test-address-group] > legend'));
        self::assertCount(0, $crawler->filter('[data-test-use-address]:not([type="radio"])'));
        foreach ($crawler->filter('[data-test-use-address]')->each(static fn ($radio): string => (string) $radio->attr('id')) as $id) {
            self::assertNotSame('', $id);
            self::assertCount(1, $crawler->filter(sprintf('label[for="%s"]', $id)));
        }
    }

    private function customerWithBook(string $email): CustomerInterface
    {
        return $this->createCustomer($email, false, true);
    }

    private function defaultAddressId(CustomerInterface $customer): int
    {
        $id = $customer->getDefaultAddress()?->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function url(int $orderId, ?string $address = null): string
    {
        return sprintf('/admin/order-drafts/%d/address', $orderId) . (null === $address ? '' : '?address=' . urlencode($address));
    }
}
