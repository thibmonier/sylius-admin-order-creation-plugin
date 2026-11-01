<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checker\SellableVariantChecker;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\AddItemType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\CouponType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ItemsType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ManualDiscountType;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\OrderAdjustmentVoter;

final class DraftItemsStepController extends AbstractController
{
    use DraftControllerTrait;
    use DraftStepTrait;

    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $creationRepository,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly SellableVariantChecker $sellableVariantChecker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function itemsAction(int $id): Response
    {
        $draft = $this->loadDraft($id);

        return $this->renderItems($draft);
    }

    public function addItemAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);

        $form = $this->createAddItemForm($order);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderItems($draft, ['add_item_form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{variant: ProductVariantInterface, quantity: int} $data */
        $data = $form->getData();
        $channel = $order->getChannel();
        if (!$channel instanceof ChannelInterface || !$this->sellableVariantChecker->isSellable($data['variant'], $channel)) {
            $form->get('variant')->addError(new FormError($this->translator->trans('tmonier_admin_order.item.not_sellable', [], 'validators')));

            return $this->renderItems($draft, ['add_item_form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->draftOrderModifier->addVariant($order, $data['variant'], $data['quantity']);
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_admin_order.item_added');

        return $this->redirectToStep('items', $draft);
    }

    public function updateItemsAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);

        $form = $this->createItemsForm($order);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderItems($draft, ['items_form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priceOverrideAllowed = $this->isGranted(OrderAdjustmentVoter::PRICE_OVERRIDE);
        $changes = [];
        foreach ($order->getItems() as $item) {
            $itemId = $item->getId();
            if (!is_int($itemId) || !$form->has(ItemsType::fieldName($itemId))) {
                continue;
            }

            /** @var array{quantity: int, unitPrice?: int|null, resetPrice?: bool} $itemData */
            $itemData = $form->get(ItemsType::fieldName($itemId))->getData();
            $changes[$itemId] = ['quantity' => $itemData['quantity']];
            if ($priceOverrideAllowed && array_key_exists('unitPrice', $itemData)) {
                $changes[$itemId]['unitPrice'] = $itemData['unitPrice'];
            } elseif (true === ($itemData['resetPrice'] ?? false)) {
                $changes[$itemId]['unitPrice'] = null;
            }
        }

        $this->draftOrderModifier->updateItems($order, $changes);
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_admin_order.items_updated');

        return $this->redirectToStep('items', $draft);
    }

    public function removeItemAction(Request $request, int $id, int $itemId): RedirectResponse
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);
        $this->assertCsrfToken($request, self::draftCsrfTokenId('remove_item', $itemId));

        $item = $order->getItems()->filter(static fn ($item): bool => $item->getId() === $itemId)->first();
        if (!$item instanceof OrderItemInterface) {
            throw new NotFoundHttpException('Item not found.');
        }

        $this->draftOrderModifier->removeItem($order, $item);
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_admin_order.item_removed');

        return $this->redirectToStep('items', $draft);
    }

    public function manualDiscountAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $this->orderOf($draft);
        $granted = $this->isGranted(OrderAdjustmentVoter::MANUAL_DISCOUNT);
        if (!$granted && null === $this->draftOrderModifier->getManualDiscount($draft)) {
            throw new AccessDeniedHttpException('Manual discounts are not allowed.');
        }

        $form = $this->createManualDiscountForm($draft);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderItems($draft, ['discount_form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{amount: int|null, reason: string|null} $data */
        $data = $form->getData();
        $amount = $data['amount'] ?? 0;
        if (!$granted && $amount > 0) {
            throw new AccessDeniedHttpException('Manual discounts are not allowed.');
        }
        $this->draftOrderModifier->setManualDiscount($draft, $amount, (string) $data['reason']);
        $this->entityManager->flush();
        $this->addFlash('success', $amount > 0 ? 'tmonier_admin_order.discount_applied' : 'tmonier_admin_order.discount_removed');

        return $this->redirectToStep('items', $draft);
    }

    public function couponAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);

        $form = $this->createCouponForm($order);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderItems($draft, ['coupon_form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{code: string|null} $data */
        $data = $form->getData();
        $code = trim((string) $data['code']);
        $applied = $this->draftOrderModifier->applyCoupon($order, '' === $code ? null : $code);
        $this->entityManager->flush();

        if (!$applied) {
            $this->addFlash('error', 'tmonier_admin_order.coupon_invalid');
        } else {
            $this->addFlash('success', '' === $code ? 'tmonier_admin_order.coupon_removed' : 'tmonier_admin_order.coupon_applied');
        }

        return $this->redirectToStep('items', $draft);
    }

    /** @param array<string, FormInterface> $forms bound forms to show with their errors */
    private function renderItems(AdminOrderCreationInterface $draft, array $forms = [], int $status = Response::HTTP_OK): Response
    {
        $order = $this->orderOf($draft);
        $forms += [
            'items_form' => $this->createItemsForm($order),
            'add_item_form' => $this->createAddItemForm($order),
            'coupon_form' => $this->createCouponForm($order),
        ];
        if ($this->isGranted(OrderAdjustmentVoter::MANUAL_DISCOUNT) || null !== $this->draftOrderModifier->getManualDiscount($draft)) {
            $forms += ['discount_form' => $this->createManualDiscountForm($draft)];
        }

        return $this->renderStep('items', $draft, array_map(static fn (FormInterface $form): \Symfony\Component\Form\FormView => $form->createView(), $forms), $status);
    }

    private function createItemsForm(OrderInterface $order): FormInterface
    {
        return $this->createForm(ItemsType::class, null, [
            'order' => $order,
            'allow_price_override' => $this->isGranted(OrderAdjustmentVoter::PRICE_OVERRIDE),
            'action' => $this->generateUrl('tmonier_admin_order_draft_items_update', ['id' => $order->getId()]),
        ]);
    }

    private function createAddItemForm(OrderInterface $order): FormInterface
    {
        return $this->createForm(AddItemType::class, null, [
            'channel_code' => (string) $order->getChannel()?->getCode(),
            'action' => $this->generateUrl('tmonier_admin_order_draft_item_add', ['id' => $order->getId()]),
        ]);
    }

    private function createCouponForm(OrderInterface $order): FormInterface
    {
        return $this->createForm(CouponType::class, ['code' => $order->getPromotionCoupon()?->getCode()], [
            'action' => $this->generateUrl('tmonier_admin_order_draft_coupon', ['id' => $order->getId()]),
        ]);
    }

    private function createManualDiscountForm(AdminOrderCreationInterface $draft): FormInterface
    {
        $order = $this->orderOf($draft);
        $discount = $this->draftOrderModifier->getManualDiscount($draft);

        return $this->createForm(ManualDiscountType::class, [
            'amount' => $discount['amount'] ?? null,
            'reason' => $discount['reason'] ?? null,
        ], [
            'currency' => (string) $order->getCurrencyCode(),
            'action' => $this->generateUrl('tmonier_admin_order_draft_manual_discount', ['id' => $order->getId()]),
        ]);
    }
}
