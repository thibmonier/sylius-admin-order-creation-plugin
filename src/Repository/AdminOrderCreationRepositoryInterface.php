<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Repository;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * @extends RepositoryInterface<AdminOrderCreationInterface>
 */
interface AdminOrderCreationRepositoryInterface extends RepositoryInterface
{
    public function findOneByOrder(OrderInterface $order): ?AdminOrderCreationInterface;

    /** The draft of the given order id, when that order is still an admin draft (cart state). */
    public function findDraftByOrderId(int $orderId): ?AdminOrderCreationInterface;

    /** @return list<AdminOrderCreationInterface> open drafts, the most recently modified first */
    public function findDrafts(int $limit): array;

    /** @return list<AdminOrderCreationInterface> drafts whose order has not been modified since the given date */
    public function findDraftsNotModifiedSince(\DateTimeInterface $date, int $limit): array;

    public function countDraftsNotModifiedSince(\DateTimeInterface $date): int;
}
