<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;
use Sylius\Bundle\CoreBundle\Order\Checker\OrderPromotionsIntegrityCheckerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPromotionsIntegrityCheckerInterface $promotionsIntegrityChecker,
        private readonly OrderRowLockInterface $orderRowLock,
        private readonly OrderEmailManagerInterface $orderEmailManager,
        private readonly PaymentLinkEmailManagerInterface $paymentLinkEmailManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TranslatorInterface $translator,
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
        return !$order->getItems()->isEmpty() &&
            in_array($order->getCheckoutState(), [OrderCheckoutStates::STATE_PAYMENT_SELECTED, OrderCheckoutStates::STATE_PAYMENT_SKIPPED], true) &&
            $this->can($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
    }

    public function recalculate(OrderInterface $order): bool
    {
        $oldTotal = $order->getTotal();
        $changed = null !== $this->promotionsIntegrityChecker->check($order) || $order->getTotal() !== $oldTotal;
        $this->dropInapplicableCoupon($order);

        return $changed;
    }

    public function validate(OrderInterface $order): ConstraintViolationListInterface
    {
        $violations = $this->validator->validate($order, null, self::VALIDATION_GROUPS);
        if ($order->getItems()->isEmpty()) {
            $message = $this->translator->trans('tmonier_admin_order.order.not_empty', [], 'validators');
            $violations->add(new ConstraintViolation($message, null, [], $order, 'items', $order->getItems()));
        }

        return $violations;
    }

    public function complete(AdminOrderCreationInterface $creation, bool $sendConfirmationEmail, bool $sendPaymentLink): CompletionResult
    {
        $order = $creation->getOrder();
        Assert::notNull($order);
        Assert::true($this->isReadyToComplete($order), 'The order is not ready to be completed.');

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            if (!$this->orderRowLock->lockDraft($order)) {
                $connection->rollBack();

                return CompletionResult::notADraft();
            }

            $this->orderRowLock->lockPromotionUsages($order);
            $oldTotal = $order->getTotal();
            $removedPromotion = $this->promotionsIntegrityChecker->check($order);
            $this->dropInapplicableCoupon($order);
            if (null !== $removedPromotion || $order->getTotal() !== $oldTotal) {
                $this->entityManager->flush();
                $connection->commit();

                return CompletionResult::changed($removedPromotion?->getName());
            }

            $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
            $creation->setCompletedAt($this->clock->now());
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

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

        // The order is placed and committed: from here on, failures are logged, never thrown.
        try {
            $creation->setConfirmationEmailSent($confirmationSent);
            $creation->setPaymentLinkSentAt($paymentLinkSent ? $this->clock->now() : null);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Unable to save the e-mail flags of the order "%s".', (string) $order->getNumber()), ['exception' => $exception]);
        }

        try {
            $this->eventDispatcher->dispatch(new AdminOrderCreatedEvent($order, $creation), AdminOrderCreatedEvent::NAME);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('A listener of "%s" failed for the order "%s".', AdminOrderCreatedEvent::NAME, (string) $order->getNumber()), ['exception' => $exception]);
        }

        return CompletionResult::placed($confirmationSent, $paymentLinkSent, $emailFailed);
    }

    /**
     * A coupon whose promotion no longer applies (usage limit reached by other orders, expired)
     * is taken off the order, so that placing it does not count one more use.
     */
    private function dropInapplicableCoupon(OrderInterface $order): void
    {
        $promotion = $order->getPromotionCoupon()?->getPromotion();
        if (null !== $order->getPromotionCoupon() && (null === $promotion || !$order->getPromotions()->contains($promotion))) {
            $order->setPromotionCoupon(null);
        }
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
