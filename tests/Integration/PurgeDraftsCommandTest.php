<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusAdminOrderCreationPlugin\Functional\AdminOrderTestTrait;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;

final class PurgeDraftsCommandTest extends WebTestCase
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

    public function testOnlyTheDraftsNotModifiedForTheLifetimeAreDeleted(): void
    {
        $old = $this->orderIdOf($this->createDraft($this->createCustomer('old.draft@example.com')));
        $recent = $this->orderIdOf($this->createDraft($this->createCustomer('recent.draft@example.com')));
        $placed = $this->placeAdminOrder($this->createCustomer('old.placed@example.com'));
        $placedId = $this->idOf($placed);
        $this->age($old, 8);
        $this->age($placedId, 30);
        $this->age($recent, 6);
        $this->entityManager()->clear();

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('1 draft order(s)', $tester->getDisplay());
        self::assertNotNull($this->entityManager()->getRepository(OrderInterface::class)->find($old));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 draft order(s)', $tester->getDisplay());

        $this->entityManager()->clear();
        $orders = $this->entityManager()->getRepository(OrderInterface::class);
        self::assertNull($orders->find($old));
        self::assertNull($this->entityManager()->getRepository(AdminOrderCreation::class)->findOneBy(['order' => $old]));
        self::assertNotNull($orders->find($recent));
        self::assertNotNull($orders->find($placedId), 'Placed orders are never purged.');

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '5']));
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(OrderInterface::class)->find($recent));
    }

    public function testTheDaysOptionMustBeAPositiveInteger(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--days' => 'abc']));
    }

    private function age(int $orderId, int $days): void
    {
        $this->connection()->executeStatement(
            'UPDATE sylius_order SET updated_at = :date, created_at = :date WHERE id = :id',
            ['date' => (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:s'), 'id' => $orderId],
        );
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('tmonier:admin-order:purge-drafts'));
    }
}
