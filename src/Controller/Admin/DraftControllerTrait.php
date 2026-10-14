<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\DraftVoter;

/**
 * @property \Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface $creationRepository
 */
trait DraftControllerTrait
{
    public const STEP_ROUTES = [
        'items' => 'tmonier_admin_order_draft_items',
        'address' => 'tmonier_admin_order_draft_address',
        'shipping_payment' => 'tmonier_admin_order_draft_shipping_payment',
        'summary' => 'tmonier_admin_order_draft_summary',
    ];

    public static function draftCsrfTokenId(string $action, int $id): string
    {
        return sprintf('tmonier_admin_order_%s_%d', $action, $id);
    }

    /**
     * The draft of the order with this id; 404 when the order is not (or no longer) an admin
     * draft, 403 when the current administrator may not open it (DraftVoter).
     */
    private function getDraft(int $orderId): AdminOrderCreationInterface
    {
        $draft = $this->creationRepository->findDraftByOrderId($orderId);
        if (null === $draft || null === $draft->getOrder()) {
            throw new NotFoundHttpException('Draft order not found.');
        }
        if (!$this->isGranted(DraftVoter::ACCESS, $draft)) {
            throw new AccessDeniedHttpException('This draft belongs to another administrator.');
        }

        return $draft;
    }

    private function redirectToStep(string $step, AdminOrderCreationInterface $draft): RedirectResponse
    {
        return $this->redirectToRoute(self::STEP_ROUTES[$step], ['id' => $draft->getOrder()?->getId()]);
    }

    private function assertCsrfToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function renderPage(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        return $this->render($template, $parameters, new Response(null, $status));
    }
}
