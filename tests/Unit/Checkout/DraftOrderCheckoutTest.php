<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Checkout;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckout;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Event\AdminOrderCreatedEvent;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface;

final class DraftOrderCheckoutTest extends TestCase
{
    private StateMachineInterface&MockObject $stateMachine;

    private ObjectManager&MockObject $entityManager;

    private OrderEmailManagerInterface&MockObject $orderEmailManager;

    private PaymentLinkEmailManagerInterface&MockObject $paymentLinkEmailManager;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private DraftOrderCheckout $checkout;

    protected function setUp(): void
    {
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->stateMachine->method('can')->willReturn(true);
        $this->entityManager = $this->createMock(ObjectManager::class);
        $this->orderEmailManager = $this->createMock(OrderEmailManagerInterface::class);
        $this->paymentLinkEmailManager = $this->createMock(PaymentLinkEmailManagerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->checkout = new DraftOrderCheckout(
            $this->stateMachine,
            $this->createMock(ValidatorInterface::class),
            $this->entityManager,
            $this->orderEmailManager,
            $this->paymentLinkEmailManager,
            $this->eventDispatcher,
            new MockClock('2026-10-11 10:00:00'),
            $this->logger,
        );
    }

    public function testCompletionPlacesTheOrderSendsTheEmailsAndDispatchesTheEvent(): void
    {
        $creation = $this->creation();
        $order = $creation->getOrder();
        self::assertNotNull($order);

        $this->stateMachine->expects(self::once())->method('apply')->with($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->entityManager->expects(self::exactly(2))->method('flush');
        $this->orderEmailManager->expects(self::once())->method('sendConfirmationEmail')->with($order);
        $this->paymentLinkEmailManager->method('canSend')->willReturn(true);
        $this->paymentLinkEmailManager->expects(self::once())->method('send')->with($order);
        $this->eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (AdminOrderCreatedEvent $event): bool => $event->getOrder() === $order && $event->getCreation() === $creation), AdminOrderCreatedEvent::NAME)
            ->willReturnArgument(0);

        $result = $this->checkout->complete($creation, true, true);

        self::assertTrue($result->confirmationEmailSent);
        self::assertTrue($result->paymentLinkSent);
        self::assertFalse($result->emailFailed);
        self::assertEquals(new \DateTimeImmutable('2026-10-11 10:00:00'), $creation->getCompletedAt());
        self::assertTrue($creation->isConfirmationEmailSent());
        self::assertNotNull($creation->getPaymentLinkSentAt());
    }

    public function testAFailingMailerDoesNotPreventThePlacement(): void
    {
        $creation = $this->creation();
        $this->orderEmailManager->method('sendConfirmationEmail')->willThrowException(new \RuntimeException('SMTP down'));
        $this->logger->expects(self::once())->method('error');
        $this->eventDispatcher->expects(self::once())->method('dispatch')->willReturnArgument(0);

        $result = $this->checkout->complete($creation, true, false);

        self::assertTrue($result->emailFailed);
        self::assertFalse($result->confirmationEmailSent);
        self::assertFalse($creation->isConfirmationEmailSent());
        self::assertNotNull($creation->getCompletedAt());
    }

    public function testNoEmailIsSentWhenNotRequested(): void
    {
        $this->orderEmailManager->expects(self::never())->method('sendConfirmationEmail');
        $this->paymentLinkEmailManager->expects(self::never())->method('send');

        $result = $this->checkout->complete($this->creation(), false, false);

        self::assertFalse($result->confirmationEmailSent);
        self::assertFalse($result->paymentLinkSent);
    }

    public function testAnOrderNotReadyCannotBeCompleted(): void
    {
        $creation = $this->creation();
        $creation->getOrder()?->setCheckoutState(OrderCheckoutStates::STATE_ADDRESSED);
        $this->stateMachine->expects(self::never())->method('apply');

        $this->expectException(\InvalidArgumentException::class);
        $this->checkout->complete($creation, true, false);
    }

    public function testAddressingNeedsBothAddresses(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->checkout->address(new Order());
    }

    private function creation(): AdminOrderCreation
    {
        $order = new Order();
        $order->setCheckoutState(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $creation = new AdminOrderCreation();
        $creation->setOrder($order);

        return $creation;
    }
}
