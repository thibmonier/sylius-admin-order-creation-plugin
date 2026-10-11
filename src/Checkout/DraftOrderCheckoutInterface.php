<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * Walks a draft through the "sylius_order_checkout" workflow, exactly like the shop checkout:
 * the core workflow listeners recalculate the order, skip the shipping and payment steps when
 * they are not needed and, on completion, assign the number and token, hold the inventory and
 * create the payment and shipment states.
 */
interface DraftOrderCheckoutInterface
{
    /** Applies the "address" transition, once the billing and shipping addresses are set. */
    public function address(OrderInterface $order): void;

    public function canSelectShipping(OrderInterface $order): bool;

    public function canSelectPayment(OrderInterface $order): bool;

    /**
     * @param array<int, ShippingMethodInterface> $shippingMethods indexed like $order->getShipments()
     */
    public function selectShippingAndPayment(OrderInterface $order, array $shippingMethods, ?PaymentMethodInterface $paymentMethod): void;

    public function isReadyToComplete(OrderInterface $order): bool;

    /** Runs the validation of the shop's last checkout step. */
    public function validate(OrderInterface $order): ConstraintViolationListInterface;

    /**
     * Places the order, flushes, sends the requested e-mails then dispatches
     * the "tmonier_admin_order.order_created" event.
     */
    public function complete(AdminOrderCreationInterface $creation, bool $sendConfirmationEmail, bool $sendPaymentLink): CompletionResult;
}
