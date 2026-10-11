<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Model\StartOrderData;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\StartOrderType;
use Tmonier\SyliusAdminOrderCreationPlugin\Remover\DraftRemoverInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Reorder\ReordererInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;

/** Starts, lists and deletes the drafts; "reorder" turns a past order into a new draft. */
final class DraftOrderController extends AbstractController
{
    use DraftControllerTrait;

    public const INDEX_LIMIT = 100;

    /**
     * @param CustomerRepositoryInterface<CustomerInterface> $customerRepository
     * @param FactoryInterface<CustomerInterface> $customerFactory
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $creationRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly FactoryInterface $customerFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DraftOrderFactoryInterface $draftOrderFactory,
        private readonly ReordererInterface $reorderer,
        private readonly EntityManagerInterface $entityManager,
        private readonly DraftRemoverInterface $draftRemover,
    ) {
    }

    public function indexAction(): Response
    {
        return $this->renderPage('@TmonierSyliusAdminOrderCreationPlugin/admin/draft_index.html.twig', [
            'drafts' => $this->creationRepository->findDrafts(self::INDEX_LIMIT),
        ]);
    }

    public function startAction(Request $request): Response
    {
        $data = new StartOrderData();
        $customerId = $request->query->getInt('customerId');
        if ($customerId > 0) {
            $customer = $this->customerRepository->find($customerId);
            $data->customer = $customer instanceof CustomerInterface ? $customer : null;
        }

        $form = $this->createForm(StartOrderType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ChannelInterface $channel */
            $channel = $data->channel;
            $customer = $data->customer ?? $this->findOrCreateCustomer($data);

            $draft = $this->draftOrderFactory->create($channel, $customer, $data->locale?->getCode(), $this->getAdminUser());
            $this->entityManager->flush();
            $this->addFlash('success', 'tmonier_admin_order.draft_created');

            return $this->redirectToStep('items', $draft);
        }

        return $this->renderPage('@TmonierSyliusAdminOrderCreationPlugin/admin/start.html.twig', [
            'form' => $form->createView(),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function deleteAction(Request $request, int $id): Response
    {
        $draft = $this->getDraft($id);
        $this->assertCsrfToken($request, self::draftCsrfTokenId('delete', $id));

        if (!$this->draftRemover->remove($draft)) {
            $this->addFlash('warning', 'tmonier_admin_order.already_placed');

            return $this->redirectToRoute('tmonier_admin_order_draft_index');
        }
        $this->addFlash('success', 'tmonier_admin_order.draft_deleted');

        return $this->redirectToRoute('tmonier_admin_order_draft_index');
    }

    public function reorderAction(Request $request, int $id): Response
    {
        $order = $this->orderRepository->find($id);
        if (!$order instanceof OrderInterface) {
            throw new NotFoundHttpException('Order not found.');
        }
        $this->assertCsrfToken($request, self::draftCsrfTokenId('reorder', $id));

        if (!$this->reorderer->canReorder($order)) {
            $this->addFlash('error', 'tmonier_admin_order.reorder_impossible');

            return $this->redirectToRoute('sylius_admin_order_show', ['id' => $id]);
        }

        $result = $this->reorderer->reorder($order, $this->getAdminUser());
        $this->entityManager->flush();

        if ([] !== $result->skippedItems) {
            $this->addFlash('warning', [
                'message' => 'tmonier_admin_order.reorder_items_skipped',
                'parameters' => ['%items%' => implode(', ', $result->skippedItems)],
            ]);
        }
        $this->addFlash('success', 'tmonier_admin_order.reorder_created');

        return $this->redirectToStep('items', $result->draft);
    }

    /** Reuses the customer with this e-mail, or creates a guest customer (no shop account). */
    private function findOrCreateCustomer(StartOrderData $data): CustomerInterface
    {
        $email = trim((string) $data->email);
        if ('' === $email) {
            throw new BadRequestHttpException('A customer or an e-mail is required.');
        }

        $customer = $this->customerRepository->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if ($customer instanceof CustomerInterface) {
            $this->addFlash('info', 'tmonier_admin_order.existing_customer_used');

            return $customer;
        }

        $customer = $this->customerFactory->createNew();
        $customer->setEmail($email);
        $customer->setFirstName($this->nullIfEmpty($data->firstName));
        $customer->setLastName($this->nullIfEmpty($data->lastName));
        $customer->setPhoneNumber($this->nullIfEmpty($data->phoneNumber));
        $this->entityManager->persist($customer);

        return $customer;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function getAdminUser(): ?AdminUserInterface
    {
        $user = $this->getUser();

        return $user instanceof AdminUserInterface ? $user : null;
    }
}
