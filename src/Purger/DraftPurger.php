<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Purger;

use Tmonier\SyliusAdminOrderCreationPlugin\Remover\DraftRemoverInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;

/**
 * Deletes abandoned admin drafts by batches: the cart (items, adjustments, addresses, payments
 * and shipments are cascaded by the core mapping) and the plugin record. A draft placed while
 * the purge runs is kept (see DraftRemover).
 */
final readonly class DraftPurger implements DraftPurgerInterface
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private AdminOrderCreationRepositoryInterface $repository,
        private DraftRemoverInterface $remover,
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
            $removedInBatch = 0;
            foreach ($drafts as $draft) {
                if ($this->remover->remove($draft)) {
                    ++$removedInBatch;
                }
            }

            $purged += $removedInBatch;
        } while (self::BATCH_SIZE === count($drafts) && $removedInBatch > 0);

        return $purged;
    }
}
