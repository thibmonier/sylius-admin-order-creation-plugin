<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Purger;

interface DraftPurgerInterface
{
    /** Number of drafts not modified since the given date. */
    public function countExpired(\DateTimeInterface $modifiedBefore): int;

    /** Deletes the drafts (and their carts) not modified since the given date; returns how many. */
    public function purge(\DateTimeInterface $modifiedBefore): int;
}
