<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Mailer;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Webmozart\Assert\Assert;

final class PaymentLinkEmailManager implements PaymentLinkEmailManagerInterface
{
    private const PAYABLE_STATES = [OrderPaymentStates::STATE_AWAITING_PAYMENT, OrderPaymentStates::STATE_PARTIALLY_PAID];

    public function __construct(
        private readonly SenderInterface $emailSender,
        private readonly bool $shopEnabled,
    ) {
    }

    public function canSend(OrderInterface $order): bool
    {
        return $this->shopEnabled
            && OrderInterface::STATE_NEW === $order->getState()
            && null !== $order->getTokenValue()
            && null !== $order->getCustomer()?->getEmail()
            && in_array($order->getPaymentState(), self::PAYABLE_STATES, true)
            && null !== $order->getLastPayment(PaymentInterface::STATE_NEW)
        ;
    }

    public function send(OrderInterface $order): void
    {
        Assert::true($this->canSend($order), 'The order cannot be paid from the shop.');

        $this->emailSender->send(
            Emails::PAYMENT_LINK,
            [(string) $order->getCustomer()?->getEmail()],
            [
                'order' => $order,
                'channel' => $order->getChannel(),
                'localeCode' => $order->getLocaleCode(),
            ],
        );
    }
}
