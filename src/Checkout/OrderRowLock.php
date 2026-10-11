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

    public function lockPromotionUsages(OrderInterface $order): void
    {
        $coupon = $order->getPromotionCoupon();
        $promotions = $order->getPromotions()->toArray();
        if (null !== $coupon) {
            $this->entityManager->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
            $couponPromotion = $coupon->getPromotion();
            if (null !== $couponPromotion && !in_array($couponPromotion, $promotions, true)) {
                $promotions[] = $couponPromotion;
            }
        }

        foreach ($promotions as $promotion) {
            if (null !== $promotion->getUsageLimit()) {
                $this->entityManager->refresh($promotion, LockMode::PESSIMISTIC_WRITE);
            }
        }
    }
}
