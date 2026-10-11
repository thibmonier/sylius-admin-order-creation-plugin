<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Twig;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Reorder\ReordererInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminOrderCreationExtension extends AbstractExtension
{
    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $repository,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly ReordererInterface $reorderer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_admin_order_creation', $this->getCreation(...)),
            new TwigFunction('tmonier_admin_order_manual_discount', $this->draftOrderModifier->getManualDiscount(...)),
            new TwigFunction('tmonier_admin_order_can_reorder', $this->reorderer->canReorder(...)),
        ];
    }

    public function getCreation(mixed $order): ?AdminOrderCreationInterface
    {
        return $order instanceof OrderInterface ? $this->repository->findOneByOrder($order) : null;
    }
}
