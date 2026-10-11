<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * @extends EntityRepository<AdminOrderCreationInterface>
 */
class AdminOrderCreationRepository extends EntityRepository implements AdminOrderCreationRepositoryInterface
{
    public function findOneByOrder(OrderInterface $order): ?AdminOrderCreationInterface
    {
        if (null === $order->getId()) {
            return null;
        }

        /** @var AdminOrderCreationInterface|null $creation */
        $creation = $this->findOneBy(['order' => $order]);

        return $creation;
    }

    public function findDraftByOrderId(int $orderId): ?AdminOrderCreationInterface
    {
        /** @var AdminOrderCreationInterface|null $creation */
        $creation = $this->createDraftQueryBuilder()
            ->andWhere('ord.id = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $creation;
    }

    public function findDrafts(int $limit): array
    {
        /** @var list<AdminOrderCreationInterface> $drafts */
        $drafts = $this->createDraftQueryBuilder()
            ->addSelect('customer')
            ->leftJoin('ord.customer', 'customer')
            ->addOrderBy('ord.updatedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult()
        ;

        return $drafts;
    }

    public function findDraftsNotModifiedSince(\DateTimeInterface $date, int $limit): array
    {
        /** @var list<AdminOrderCreationInterface> $drafts */
        $drafts = $this->createDraftQueryBuilder()
            ->andWhere('COALESCE(ord.updatedAt, ord.createdAt) < :date')
            ->setParameter('date', $date)
            ->addOrderBy('o.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult()
        ;

        return $drafts;
    }

    public function countDraftsNotModifiedSince(\DateTimeInterface $date): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->innerJoin('o.order', 'ord')
            ->andWhere('o.completedAt IS NULL')
            ->andWhere('ord.state = :cartState')
            ->andWhere('COALESCE(ord.updatedAt, ord.createdAt) < :date')
            ->setParameter('cartState', OrderInterface::STATE_CART)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    private function createDraftQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('ord')
            ->innerJoin('o.order', 'ord')
            ->andWhere('o.completedAt IS NULL')
            ->andWhere('ord.state = :cartState')
            ->setParameter('cartState', OrderInterface::STATE_CART)
        ;
    }
}
