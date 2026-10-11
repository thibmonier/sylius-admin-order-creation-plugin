<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Serialises the operations on a draft (placement, deletion, purge): locks the order row for
 * the current transaction and reads its state again from the database.
 */
final class OrderRowLock
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Must run inside a transaction. Returns false when the order is no longer a cart. */
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
