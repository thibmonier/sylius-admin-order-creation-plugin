<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Reorder;

use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

final readonly class ReorderResult
{
    /** @param list<string> $skippedItems names of the items that are no longer sold in the channel */
    public function __construct(
        public AdminOrderCreationInterface $draft,
        public array $skippedItems,
    ) {
    }
}
