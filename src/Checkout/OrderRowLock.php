<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;

final class OrderRowLock implements OrderRowLockInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function lockDraft(OrderInterface $order): bool
    {
        if (null === $order->getId()) {
            return false;
        }

        $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);

        $tableName = $this->entityManager->getClassMetadata($order::class)->getTableName();
        $state = $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT state FROM %s WHERE id = ?', $tableName),
            [$order->getId()],
        );

        return OrderInterface::STATE_CART === $state;
    }
}
