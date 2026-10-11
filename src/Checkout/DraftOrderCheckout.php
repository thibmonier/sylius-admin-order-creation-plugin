<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Event\AdminOrderCreatedEvent;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface;
use Webmozart\Assert\Assert;

final class DraftOrderCheckout implements DraftOrderCheckoutInterface
{
    public const VALIDATION_GROUPS = ['sylius', 'sylius_checkout_complete'];

    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly ValidatorInterface $validator,
        private readonly ObjectManager $entityManager,
        private readonly OrderEmailManagerInterface $orderEmailManager,
        private readonly PaymentLinkEmailManagerInterface $paymentLinkEmailManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function address(OrderInterface $order): void
    {
        Assert::notNull($order->getBillingAddress(), 'The order has no billing address.');
        Assert::notNull($order->getShippingAddress(), 'The order has no shipping address.');

        $this->apply($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);
    }

    public function canSelectShipping(OrderInterface $order): bool
    {
        return $this->can($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
    }

    public function canSelectPayment(OrderInterface $order): bool
    {
        return $this->can($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
    }

    public function selectShippingAndPayment(OrderInterface $order, array $shippingMethods, ?PaymentMethodInterface $paymentMethod): void
    {
        if ([] !== $shippingMethods && $this->canSelectShipping($order)) {
            foreach (array_values($order->getShipments()->toArray()) as $index => $shipment) {
                if (isset($shippingMethods[$index])) {
                    $shipment->setMethod($shippingMethods[$index]);
                }
            }

            $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        }

        if (null !== $paymentMethod && $this->canSelectPayment($order)) {
            $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
            Assert::notNull($payment, 'The order has no payment to select a method for.');
            $payment->setMethod($paymentMethod);

            $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        }
    }

    public function isReadyToComplete(OrderInterface $order): bool
    {
        return in_array($order->getCheckoutState(), [OrderCheckoutStates::STATE_PAYMENT_SELECTED, OrderCheckoutStates::STATE_PAYMENT_SKIPPED], true)
            && $this->can($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
    }

    public function validate(OrderInterface $order): ConstraintViolationListInterface
    {
        return $this->validator->validate($order, null, self::VALIDATION_GROUPS);
    }

    public function complete(AdminOrderCreationInterface $creation, bool $sendConfirmationEmail, bool $sendPaymentLink): CompletionResult
    {
        $order = $creation->getOrder();
        Assert::notNull($order);
        Assert::true($this->isReadyToComplete($order), 'The order is not ready to be completed.');

        $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $creation->setCompletedAt($this->clock->now());
        $this->entityManager->flush();

        $emailFailed = false;
        $confirmationSent = false;
        if ($sendConfirmationEmail) {
            $confirmationSent = $this->sendEmail(fn () => $this->orderEmailManager->sendConfirmationEmail($order), $order, 'order confirmation');
            $emailFailed = !$confirmationSent;
        }

        $paymentLinkSent = false;
        if ($sendPaymentLink && $this->paymentLinkEmailManager->canSend($order)) {
            $paymentLinkSent = $this->sendEmail(fn () => $this->paymentLinkEmailManager->send($order), $order, 'payment link');
            $emailFailed = $emailFailed || !$paymentLinkSent;
        }

        $creation->setConfirmationEmailSent($confirmationSent);
        $creation->setPaymentLinkSentAt($paymentLinkSent ? $this->clock->now() : null);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new AdminOrderCreatedEvent($order, $creation), AdminOrderCreatedEvent::NAME);

        return new CompletionResult($confirmationSent, $paymentLinkSent, $emailFailed);
    }

    /** The order is placed whatever happens: a failing mailer only produces a warning. */
    private function sendEmail(callable $send, OrderInterface $order, string $email): bool
    {
        try {
            $send();

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Unable to send the %s e-mail of the order "%s".', $email, (string) $order->getNumber()), ['exception' => $exception]);

            return false;
        }
    }

    private function can(OrderInterface $order, string $transition): bool
    {
        return $this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition);
    }

    private function apply(OrderInterface $order, string $transition): void
    {
        Assert::true($this->can($order, $transition), sprintf('The "%s" checkout transition cannot be applied to the order.', $transition));

        $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
    }
}
