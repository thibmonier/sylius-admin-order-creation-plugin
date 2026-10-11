<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Reorder;

use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

final class ReorderResult
{
    /** @param list<string> $skippedItems names of the items that are no longer sold in the channel */
    public function __construct(
        public readonly AdminOrderCreationInterface $draft,
        public readonly array $skippedItems,
    ) {
    }
}
