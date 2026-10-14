<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Remover;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\OrderRowLockInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

final readonly class DraftRemover implements DraftRemoverInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRowLockInterface $orderRowLock,
    ) {
    }

    public function remove(AdminOrderCreationInterface $draft): bool
    {
        $order = $draft->getOrder();
        if (!$order instanceof \Sylius\Component\Core\Model\OrderInterface) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            if (!$this->orderRowLock->lockDraft($order)) {
                $connection->rollBack();

                return false;
            }

            $this->entityManager->remove($draft);
            $this->entityManager->remove($order);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return true;
    }
}
