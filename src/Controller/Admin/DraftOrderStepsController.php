<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checker\SellableVariantChecker;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\CompletionResult;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckoutInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\AddItemType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\AddressStepType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\CompleteType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\CouponType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ItemsType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ManualDiscountType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ShippingPaymentType;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\OrderAdjustmentVoter;

/**
 * The steps of a draft: items (with live totals), addresses, shipping and payment, summary.
 * Every change is a POST followed by a redirect; the totals shown are the ones computed by the
 * core order processor.
 */
final class DraftOrderStepsController extends AbstractController
{
    use DraftControllerTrait;

    /**
     * @param FactoryInterface<AddressInterface> $addressFactory
     * @param ServiceRegistryInterface $shippingCalculators registry of CalculatorInterface
     */
    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $creationRepository,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly DraftOrderCheckoutInterface $checkout,
        private readonly ShippingMethodsResolverInterface $shippingMethodsResolver,
        private readonly PaymentMethodsResolverInterface $paymentMethodsResolver,
        private readonly ServiceRegistryInterface $shippingCalculators,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly FactoryInterface $addressFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly SellableVariantChecker $sellableVariantChecker,
        private readonly TranslatorInterface $translator,
        private readonly bool $sendConfirmationEmailDefault,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** The draft, cleaned of the custom prices or discount of a feature disabled since. */
    private function loadDraft(int $orderId): AdminOrderCreationInterface
    {
        $draft = $this->getDraft($orderId);
        if ($this->draftOrderModifier->enforceFeatureSwitches($draft)) {
            $this->entityManager->flush();
        }

        return $draft;
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
                // Without the permission, a custom price can be removed, not set.
                $changes[$itemId]['unitPrice'] = null;
            }
        }

        $this->draftOrderModifier->updateItems($order, $changes);
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_admin_order.items_updated');

        return $this->redirectToStep('items', $draft);
    }

    public function removeItemAction(Request $request, int $id, int $itemId): Response
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
        $order = $this->orderOf($draft);
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
            // Without the permission, an existing discount can be removed, not set.
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

    public function addressAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);
        if ($order->getItems()->isEmpty()) {
            $this->addFlash('error', 'tmonier_admin_order.no_items');

            return $this->redirectToStep('items', $draft);
        }

        $channel = $order->getChannel();
        \assert($channel instanceof ChannelInterface);

        $form = $this->createForm(AddressStepType::class, $this->getAddressData($request, $order), [
            'channel' => $channel,
            'action' => $this->generateUrl('tmonier_admin_order_draft_address', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{billingAddress: AddressInterface, shippingAddress: AddressInterface, differentShippingAddress: bool} $data */
            $data = $form->getData();
            $order->setBillingAddress($data['billingAddress']);
            $order->setShippingAddress($data['shippingAddress']);
            $this->checkout->address($order);
            $this->entityManager->flush();

            return $this->redirectToStep('shipping_payment', $draft);
        }

        return $this->renderStep('address', $draft, [
            'form' => $form->createView(),
            'address_book' => $this->getAddressBook($order),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function shippingPaymentAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);
        if (in_array($order->getCheckoutState(), ['cart'], true)) {
            return $this->redirectToStep('address', $draft);
        }

        $shipments = $this->checkout->canSelectShipping($order) ? $this->getShipmentChoices($order) : [];
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        $paymentMethods = null;
        // From "addressed", the payment method becomes selectable once the shipping one is.
        if (null !== $payment && ($this->checkout->canSelectPayment($order) || [] !== $shipments)) {
            /** @var list<PaymentMethodInterface> $paymentMethods */
            $paymentMethods = array_values($this->paymentMethodsResolver->getSupportedMethods($payment));
        }

        if ([] === $shipments && null === $paymentMethods) {
            return $this->redirectToStep('summary', $draft);
        }

        $currentPaymentMethod = $payment?->getMethod();
        $form = $this->createForm(ShippingPaymentType::class, null, [
            'shipments' => $shipments,
            'payment_methods' => $paymentMethods,
            'selected_payment_method' => $currentPaymentMethod instanceof PaymentMethodInterface && in_array($currentPaymentMethod, $paymentMethods ?? [], true) ? $currentPaymentMethod : null,
            'action' => $this->generateUrl('tmonier_admin_order_draft_shipping_payment', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $shippingMethods = [];
            foreach (array_keys($shipments) as $index) {
                $method = $form->get(ShippingPaymentType::shipmentFieldName($index))->getData();
                if ($method instanceof ShippingMethodInterface) {
                    $shippingMethods[$index] = $method;
                }
            }
            $paymentMethod = $form->has('paymentMethod') ? $form->get('paymentMethod')->getData() : null;

            $this->checkout->selectShippingAndPayment(
                $order,
                $shippingMethods,
                $paymentMethod instanceof PaymentMethodInterface ? $paymentMethod : null,
            );
            $this->entityManager->flush();

            return $this->redirectToStep('summary', $draft);
        }

        return $this->renderStep('shipping_payment', $draft, [
            'form' => $form->createView(),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
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
            'can_send_payment_link' => $order->getTotal() > 0 && null !== $order->getLastPayment(PaymentInterface::STATE_CART),
            'action' => $this->generateUrl('tmonier_admin_order_draft_summary', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() && $this->checkout->recalculate($order)) {
            // Prices or promotions changed since the draft was last modified: show the current totals.
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

    /** Reads the stored state: the entity manager may be closed after a failure. */
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

        return $this->renderStep('items', $draft, array_map(static fn (FormInterface $form) => $form->createView(), $forms), $status);
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

    /**
     * The order addresses, else a copy of an address of the customer's address book (?address=id),
     * else the customer's default address; the shipping address defaults to the billing one.
     *
     * @return array{billingAddress: AddressInterface, shippingAddress: AddressInterface, differentShippingAddress: bool}
     */
    private function getAddressData(Request $request, OrderInterface $order): array
    {
        // The order's own address entities are edited in place (no orphan address rows).
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $different = null !== $billing && null !== $shipping && !$this->sameAddress($billing, $shipping);

        $source = null;
        $bookAddressId = $request->query->getInt('address');
        foreach ($bookAddressId > 0 ? $this->getAddressBook($order) : [] as $bookAddress) {
            if ($bookAddress->getId() === $bookAddressId) {
                $source = $bookAddress;
                $different = false;
            }
        }
        if (null === $billing && null === $source) {
            $source = $this->getCustomer($order)?->getDefaultAddress();
        }

        $billing ??= $this->addressFactory->createNew();
        if (null !== $source) {
            $this->copyInto($source, $billing);
        }
        $shipping ??= $this->addressFactory->createNew();
        if (!$different) {
            $this->copyInto($billing, $shipping);
        }

        if (null === $billing->getFirstName() && null === $billing->getLastName()) {
            $billing->setFirstName($this->getCustomer($order)?->getFirstName());
            $billing->setLastName($this->getCustomer($order)?->getLastName());
            $billing->setPhoneNumber($this->getCustomer($order)?->getPhoneNumber());
        }

        return [
            'billingAddress' => $billing,
            'shippingAddress' => $shipping,
            'differentShippingAddress' => $different,
        ];
    }

    /** @return list<AddressInterface> */
    private function getAddressBook(OrderInterface $order): array
    {
        $customer = $this->getCustomer($order);
        if (null === $customer) {
            return [];
        }

        /** @var list<AddressInterface> $addresses */
        $addresses = array_values($customer->getAddresses()->toArray());

        return $addresses;
    }

    private function getCustomer(OrderInterface $order): ?CustomerInterface
    {
        $customer = $order->getCustomer();

        return $customer instanceof CustomerInterface ? $customer : null;
    }

    private function copyInto(AddressInterface $address, AddressInterface $copy): void
    {
        $copy->setFirstName($address->getFirstName());
        $copy->setLastName($address->getLastName());
        $copy->setPhoneNumber($address->getPhoneNumber());
        $copy->setCompany($address->getCompany());
        $copy->setStreet($address->getStreet());
        $copy->setCity($address->getCity());
        $copy->setPostcode($address->getPostcode());
        $copy->setCountryCode($address->getCountryCode());
        $copy->setProvinceCode($address->getProvinceCode());
        $copy->setProvinceName($address->getProvinceName());
    }

    private function sameAddress(AddressInterface $first, AddressInterface $second): bool
    {
        foreach (['getFirstName', 'getLastName', 'getPhoneNumber', 'getCompany', 'getStreet', 'getCity', 'getPostcode', 'getCountryCode', 'getProvinceCode', 'getProvinceName'] as $getter) {
            if ($first->$getter() !== $second->$getter()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array{methods: list<ShippingMethodInterface>, labels: array<string, string>, selected: ShippingMethodInterface|null}> */
    private function getShipmentChoices(OrderInterface $order): array
    {
        $choices = [];
        foreach (array_values($order->getShipments()->toArray()) as $index => $shipment) {
            /** @var list<ShippingMethodInterface> $methods */
            $methods = array_values($this->shippingMethodsResolver->getSupportedMethods($shipment));
            $labels = [];
            foreach ($methods as $method) {
                $labels[(string) $method->getCode()] = $this->shippingMethodLabel($order, $shipment, $method);
            }

            $current = $shipment->getMethod();
            $choices[$index] = [
                'methods' => $methods,
                'labels' => $labels,
                'selected' => $current instanceof ShippingMethodInterface && in_array($current, $methods, true) ? $current : null,
            ];
        }

        return $choices;
    }

    private function shippingMethodLabel(OrderInterface $order, ShipmentInterface $shipment, ShippingMethodInterface $method): string
    {
        try {
            $calculator = $this->shippingCalculators->get((string) $method->getCalculator());
            \assert($calculator instanceof CalculatorInterface);
            $fee = $calculator->calculate($shipment, $method->getConfiguration());

            return sprintf('%s (%s)', (string) $method->getName(), $this->moneyFormatter->format($fee, (string) $order->getCurrencyCode(), $order->getLocaleCode()));
        } catch (\Throwable) {
            return (string) $method->getName();
        }
    }

    private function orderOf(AdminOrderCreationInterface $draft): OrderInterface
    {
        $order = $draft->getOrder();
        \assert($order instanceof OrderInterface);

        return $order;
    }
}
