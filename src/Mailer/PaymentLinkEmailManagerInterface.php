<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Mailer;

use Sylius\Component\Core\Model\OrderInterface;

interface PaymentLinkEmailManagerInterface
{
    /** Whether the order can still be paid by the customer from the shop. */
    public function canSend(OrderInterface $order): bool;

    /**
     * Sends the customer a link to the shop's order page (/order/{tokenValue}), where the
     * standard "pay" flow of the shop lets them choose a payment method and pay.
     */
    public function send(OrderInterface $order): void;
}
