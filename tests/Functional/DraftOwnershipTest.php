<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * restrict_drafts_to_owner: the test application reads the option from
 * TMONIER_ADMIN_ORDER_RESTRICT_DRAFTS, set before each kernel boots.
 */
final class DraftOwnershipTest extends WebTestCase
{
    use AdminOrderTestTrait;

    protected function tearDown(): void
    {
        $this->tearDownClient();
        unset($_SERVER['TMONIER_ADMIN_ORDER_RESTRICT_DRAFTS'], $_ENV['TMONIER_ADMIN_ORDER_RESTRICT_DRAFTS']);
        parent::tearDown();
    }

    public function testDraftsAreSharedBetweenAdministratorsByDefault(): void
    {
        $this->boot(false);
        $colleagueDraft = $this->createDraft($this->createCustomer('shared@example.com'), $this->createColleague());
        $this->loginAdmin();

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $this->orderIdOf($colleagueDraft)));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/order-drafts');
        self::assertSelectorExists(sprintf('[data-test-draft="%d"]', $this->orderIdOf($colleagueDraft)));
    }

    public function testOnlyTheAuthorOpensTheirDraftsWhenRestricted(): void
    {
        $this->boot(true);
        $colleagueDraft = $this->createDraft($this->createCustomer('colleague@example.com'), $this->createColleague());
        $admin = $this->loginAdmin();
        $ownDraft = $this->createDraft($this->createCustomer('own@example.com'), $admin);
        $orphanDraft = $this->createDraft($this->createCustomer('orphan@example.com'));
        $colleagueOrderId = $this->orderIdOf($colleagueDraft);

        // Every draft action loads the draft through the same access check (DraftControllerTrait).
        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $colleagueOrderId));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/admin/order-drafts/%d/items', $this->orderIdOf($ownDraft)));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/order-drafts');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('[data-test-draft="%d"]', $this->orderIdOf($ownDraft)));
        self::assertSelectorExists(sprintf('[data-test-draft="%d"]', $this->orderIdOf($orphanDraft)), 'Drafts whose author was deleted stay open.');
        self::assertSelectorNotExists(sprintf('[data-test-draft="%d"]', $colleagueOrderId));
    }

    private function boot(bool $restrictDraftsToOwner): void
    {
        $_SERVER['TMONIER_ADMIN_ORDER_RESTRICT_DRAFTS'] = $_ENV['TMONIER_ADMIN_ORDER_RESTRICT_DRAFTS'] = $restrictDraftsToOwner ? '1' : '0';
        $this->setUpClient();
    }

    /** Another administrator; never logged in, so without a password. */
    private function createColleague(): AdminUserInterface
    {
        /** @var FactoryInterface<AdminUserInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.admin_user');
        $colleague = $factory->createNew();
        $colleague->setUsername('tmonier_colleague');
        $colleague->setEmail('tmonier.colleague@example.com');
        $colleague->setLocaleCode('en_US');
        $colleague->setEnabled(true);
        $this->entityManager()->persist($colleague);
        $this->entityManager()->flush();

        return $colleague;
    }
}
