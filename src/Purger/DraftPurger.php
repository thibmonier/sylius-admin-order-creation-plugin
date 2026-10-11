<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Purger;

use Doctrine\Persistence\ObjectManager;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;

/**
 * Deletes abandoned admin drafts by batches: the cart (items, adjustments, addresses, payments
 * and shipments are cascaded by the core mapping) and the plugin record.
 */
final class DraftPurger implements DraftPurgerInterface
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $repository,
        private readonly ObjectManager $entityManager,
    ) {
    }

    public function countExpired(\DateTimeInterface $modifiedBefore): int
    {
        return $this->repository->countDraftsNotModifiedSince($modifiedBefore);
    }

    public function purge(\DateTimeInterface $modifiedBefore): int
    {
        $purged = 0;
        do {
            $drafts = $this->repository->findDraftsNotModifiedSince($modifiedBefore, self::BATCH_SIZE);
            foreach ($drafts as $draft) {
                $order = $draft->getOrder();
                $this->entityManager->remove($draft);
                if (null !== $order) {
                    $this->entityManager->remove($order);
                }
            }

            $this->entityManager->flush();
            $purged += count($drafts);
        } while (self::BATCH_SIZE === count($drafts));

        return $purged;
    }
}
