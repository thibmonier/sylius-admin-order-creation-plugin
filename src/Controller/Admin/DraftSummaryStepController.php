<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\CompletionResult;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckoutInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\CompleteType;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;

final class DraftSummaryStepController extends AbstractController
{
    use DraftControllerTrait;
    use DraftStepTrait;

    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $creationRepository,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly DraftOrderCheckoutInterface $checkout,
        private readonly EntityManagerInterface $entityManager,
        private readonly bool $sendConfirmationEmailDefault,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function summaryAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);
        if ($order->getItems()->isEmpty()) {
            $this->addFlash('error', 'tmonier_admin_order.no_items');

            return $this->redirectToStep('items', $draft);
        }
        if (!$this->checkout->isReadyToComplete($order)) {
            return $this->redirectToStep('cart' === $order->getCheckoutState() ? 'address' : 'shipping_payment', $draft);
        }

        $form = $this->createForm(CompleteType::class, ['notes' => $order->getNotes()], [
            'send_confirmation_email' => $this->sendConfirmationEmailDefault,
            'can_send_payment_link' => $order->getTotal() > 0 && $order->getLastPayment(PaymentInterface::STATE_CART) instanceof \Sylius\Component\Core\Model\PaymentInterface,
            'action' => $this->generateUrl('tmonier_admin_order_draft_summary', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() && $this->checkout->recalculate($order)) {
            $this->entityManager->flush();
            $this->addFlash('info', 'tmonier_admin_order.totals_updated');
        }

        $violations = $this->checkout->validate($order);
        if ($form->isSubmitted() && $form->isValid() && 0 === count($violations)) {
            /** @var array{notes: string|null, sendConfirmationEmail: bool, sendPaymentLink?: bool} $data */
            $data = $form->getData();
            $notes = trim((string) $data['notes']);
            $order->setNotes('' === $notes ? null : $notes);

            try {
                $result = $this->checkout->complete($draft, $data['sendConfirmationEmail'], $data['sendPaymentLink'] ?? false);
            } catch (\Throwable $exception) {
                $this->logger?->error('The admin order could not be placed.', ['exception' => $exception]);
                if ($this->isPlaced($order)) {
                    $this->addFlash('warning', 'tmonier_admin_order.placed_with_errors');

                    return $this->redirectToRoute('sylius_admin_order_show', ['id' => $id]);
                }

                $this->addFlash('error', 'tmonier_admin_order.completion_failed');

                return $this->redirectToStep('summary', $draft);
            }

            if (CompletionResult::NOT_A_DRAFT === $result->status) {
                $this->addFlash('warning', 'tmonier_admin_order.already_placed');

                return $this->redirectToRoute('sylius_admin_order_show', ['id' => $order->getId()]);
            }

            if (CompletionResult::CHANGED === $result->status) {
                $this->addFlash('warning', null !== $result->removedPromotion
                    ? ['message' => 'tmonier_admin_order.promotion_changed', 'parameters' => ['%promotion%' => $result->removedPromotion]]
                    : 'tmonier_admin_order.total_changed');

                return $this->redirectToStep('summary', $draft);
            }

            $this->addFlash('success', ['message' => 'tmonier_admin_order.order_placed', 'parameters' => ['%number%' => (string) $order->getNumber()]]);
            if ($result->paymentLinkSent) {
                $this->addFlash('info', 'tmonier_admin_order.payment_link_sent');
            }
            if ($result->emailFailed) {
                $this->addFlash('warning', 'tmonier_admin_order.email_failed');
            }
            if ($result->postCompletionError) {
                $this->addFlash('warning', 'tmonier_admin_order.placed_with_errors');
            }

            return $this->redirectToRoute('sylius_admin_order_show', ['id' => $order->getId()]);
        }

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $this->renderStep('summary', $draft, [
            'form' => $form->createView(),
            'violations' => array_values(array_unique($messages)),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function isPlaced(OrderInterface $order): bool
    {
        try {
            $tableName = $this->entityManager->getClassMetadata($order::class)->getTableName();
            $state = $this->entityManager->getConnection()->fetchOne(sprintf('SELECT state FROM %s WHERE id = ?', $tableName), [$order->getId()]);
        } catch (\Throwable) {
            return false;
        }

        return false !== $state && OrderInterface::STATE_CART !== $state;
    }
}
