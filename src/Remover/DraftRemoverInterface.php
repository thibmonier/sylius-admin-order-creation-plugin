<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Remover;

use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

interface DraftRemoverInterface
{
    /**
     * Deletes the draft and its cart, unless the order has been placed in the meantime (the
     * order row is locked and its state read again). Returns whether it was deleted.
     */
    public function remove(AdminOrderCreationInterface $draft): bool;
}
