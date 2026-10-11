<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\Emails;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManager;

final class PaymentLinkEmailManagerTest extends TestCase
{
    public function testItSendsThePaymentLinkOfAnOrderAwaitingPayment(): void
    {
        $order = $this->placedOrder();
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->with(
            Emails::PAYMENT_LINK,
            ['ada@example.com'],
            self::callback(static fn (array $data): bool => $data['order'] === $order && 'fr_FR' === $data['localeCode'] && $data['channel'] === $order->getChannel()),
        );

        $manager = new PaymentLinkEmailManager($sender, true);
        self::assertTrue($manager->canSend($order));
        $manager->send($order);
    }

    public function testItCannotSendWhenTheOrderCannotBePaidFromTheShop(): void
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');

        self::assertFalse((new PaymentLinkEmailManager($sender, false))->canSend($this->placedOrder()), 'No shop, no link.');

        $manager = new PaymentLinkEmailManager($sender, true);
        $paid = $this->placedOrder();
        $paid->setPaymentState(OrderPaymentStates::STATE_PAID);
        self::assertFalse($manager->canSend($paid));

        $cart = $this->placedOrder();
        $cart->setState(OrderInterface::STATE_CART);
        self::assertFalse($manager->canSend($cart));

        $this->expectException(\InvalidArgumentException::class);
        $manager->send($paid);
    }

    private function placedOrder(): OrderInterface
    {
        $customer = new Customer();
        $customer->setEmail('ada@example.com');

        $order = new Order();
        $order->setChannel(new Channel());
        $order->setCustomer($customer);
        $order->setLocaleCode('fr_FR');
        $order->setState(OrderInterface::STATE_NEW);
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $order->setTokenValue('token');

        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);

        return $order;
    }
}
