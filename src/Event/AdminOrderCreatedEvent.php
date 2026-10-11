<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Event;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * Dispatched once an order built in the back office has been placed (checkout completed and
 * flushed, e-mails sent).
 */
final class AdminOrderCreatedEvent extends Event
{
    public const NAME = 'tmonier_admin_order.order_created';

    public function __construct(
        private readonly OrderInterface $order,
        private readonly AdminOrderCreationInterface $creation,
    ) {
    }

    public function getOrder(): OrderInterface
    {
        return $this->order;
    }

    public function getCreation(): AdminOrderCreationInterface
    {
        return $this->creation;
    }

    public function getAdminUser(): ?AdminUserInterface
    {
        return $this->creation->getAdminUser();
    }
}
