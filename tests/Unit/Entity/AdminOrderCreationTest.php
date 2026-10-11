<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;

final class AdminOrderCreationTest extends TestCase
{
    public function testADraftIsACartNotCompletedYet(): void
    {
        $order = new Order();
        $creation = new AdminOrderCreation();
        self::assertFalse($creation->isDraft(), 'No order, no draft.');

        $creation->setOrder($order);
        self::assertTrue($creation->isDraft());

        $creation->setCompletedAt(new \DateTimeImmutable());
        self::assertFalse($creation->isDraft());

        $creation->setCompletedAt(null);
        $order->setState(OrderInterface::STATE_NEW);
        self::assertFalse($creation->isDraft());
    }

    public function testTheAdministratorNameIsKeptWhenTheAccountIsRemoved(): void
    {
        $admin = new AdminUser();
        $admin->setUsername('jdoe');
        $admin->setFirstName('John');
        $admin->setLastName('Doe');

        $creation = new AdminOrderCreation();
        $creation->setAdminUser($admin);
        self::assertSame('John Doe', $creation->getAdminName());

        $creation->setAdminUser(null);
        self::assertNull($creation->getAdminUser());
        self::assertSame('John Doe', $creation->getAdminName());
    }

    public function testTheUsernameIsUsedWhenTheAdministratorHasNoName(): void
    {
        $admin = new AdminUser();
        $admin->setUsername('phone-desk');

        $creation = new AdminOrderCreation();
        $creation->setAdminUser($admin);

        self::assertSame('phone-desk', $creation->getAdminName());
    }

    public function testDefaults(): void
    {
        $before = new \DateTimeImmutable();
        $creation = new AdminOrderCreation();

        self::assertGreaterThanOrEqual($before, $creation->getCreatedAt());
        self::assertNull($creation->getCompletedAt());
        self::assertFalse($creation->isConfirmationEmailSent());
        self::assertNull($creation->getPaymentLinkSentAt());
        self::assertNull($creation->getSourceOrder());
    }
}
