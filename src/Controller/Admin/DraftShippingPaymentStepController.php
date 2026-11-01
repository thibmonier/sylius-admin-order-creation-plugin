<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckoutInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ShippingPaymentType;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;

final class DraftShippingPaymentStepController extends AbstractController
{
    use DraftControllerTrait;
    use DraftStepTrait;

    /** @param ServiceRegistryInterface $shippingCalculators registry of CalculatorInterface */
    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $creationRepository,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly DraftOrderCheckoutInterface $checkout,
        private readonly ShippingMethodsResolverInterface $shippingMethodsResolver,
        private readonly PaymentMethodsResolverInterface $paymentMethodsResolver,
        private readonly ServiceRegistryInterface $shippingCalculators,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function shippingPaymentAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);
        if ($order->getCheckoutState() === 'cart') {
            return $this->redirectToStep('address', $draft);
        }

        $shipments = $this->checkout->canSelectShipping($order) ? $this->getShipmentChoices($order) : [];
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        $paymentMethods = null;
        if ($payment instanceof \Sylius\Component\Core\Model\PaymentInterface && ($this->checkout->canSelectPayment($order) || [] !== $shipments)) {
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
}
