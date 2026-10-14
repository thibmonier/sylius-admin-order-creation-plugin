<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;

final readonly class OrderRowLock implements OrderRowLockInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function lockDraft(OrderInterface $order): bool
    {
        if (null === $order->getId()) {
            return false;
        }

        // A locking read: under REPEATABLE READ, the first plain read of the transaction fixes its
        // snapshot, which must not predate the commits this transaction waits for on the locks.
        $tableName = $this->entityManager->getClassMetadata($order::class)->getTableName();
        $state = $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT state FROM %s WHERE id = ? FOR UPDATE', $tableName),
            [$order->getId()],
        );

        return OrderInterface::STATE_CART === $state;
    }

    public function lockPromotionUsages(OrderInterface $order): void
    {
        // Locking reads only (refresh with a lock mode is a SELECT ... FOR UPDATE).
        $coupon = $order->getPromotionCoupon();
        $promotions = $order->getPromotions()->toArray();
        if ($coupon instanceof \Sylius\Component\Promotion\Model\PromotionCouponInterface) {
            $this->entityManager->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
            $couponPromotion = $coupon->getPromotion();
            if ($couponPromotion instanceof \Sylius\Component\Promotion\Model\PromotionInterface && !in_array($couponPromotion, $promotions, true)) {
                $promotions[] = $couponPromotion;
            }
        }

        foreach ($promotions as $promotion) {
            $this->entityManager->refresh($promotion, LockMode::PESSIMISTIC_WRITE);
        }
    }
}
