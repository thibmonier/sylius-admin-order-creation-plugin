<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Response;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\OrderAdjustmentVoter;

/**
 * @property \Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface $draftOrderModifier
 * @property \Doctrine\ORM\EntityManagerInterface $entityManager
 */
trait DraftStepTrait
{
    private function loadDraft(int $orderId): AdminOrderCreationInterface
    {
        $draft = $this->getDraft($orderId);
        if ($this->draftOrderModifier->enforceFeatureSwitches($draft)) {
            $this->entityManager->flush();
        }

        return $draft;
    }

    private function orderOf(AdminOrderCreationInterface $draft): OrderInterface
    {
        $order = $draft->getOrder();
        \assert($order instanceof OrderInterface);

        return $order;
    }

    /** @param array<string, mixed> $parameters */
    private function renderStep(string $step, AdminOrderCreationInterface $draft, array $parameters, int $status = Response::HTTP_OK): Response
    {
        return $this->renderPage('@TmonierSyliusAdminOrderCreationPlugin/admin/draft.html.twig', [
            'step' => $step,
            'draft' => $draft,
            'order' => $draft->getOrder(),
            'price_override_allowed' => $this->isGranted(OrderAdjustmentVoter::PRICE_OVERRIDE),
            'manual_discount' => $this->draftOrderModifier->getManualDiscount($draft),
            'manual_discount_allowed' => $this->isGranted(OrderAdjustmentVoter::MANUAL_DISCOUNT),
        ] + $parameters, $status);
    }
}
