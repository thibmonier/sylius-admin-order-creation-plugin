<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\Mailer\OrderEmailManagerInterface;
use Sylius\Bundle\CoreBundle\Order\Checker\OrderPromotionsIntegrityCheckerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\Promotion;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\CompletionResult;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckout;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\OrderRowLockInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Event\AdminOrderCreatedEvent;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface;

final class DraftOrderCheckoutTest extends TestCase
{
    private StateMachineInterface&MockObject $stateMachine;

    private EntityManagerInterface&MockObject $entityManager;

    private Connection&MockObject $connection;

    private OrderPromotionsIntegrityCheckerInterface&MockObject $integrityChecker;

    private OrderRowLockInterface&MockObject $rowLock;

    private OrderEmailManagerInterface&MockObject $orderEmailManager;

    private PaymentLinkEmailManagerInterface&MockObject $paymentLinkEmailManager;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private DraftOrderCheckout $checkout;

    protected function setUp(): void
    {
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->stateMachine->method('can')->willReturn(true);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('isTransactionActive')->willReturn(true);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->integrityChecker = $this->createMock(OrderPromotionsIntegrityCheckerInterface::class);
        $this->rowLock = $this->createMock(OrderRowLockInterface::class);
        $this->rowLock->method('lockDraft')->willReturn(true);
        $this->orderEmailManager = $this->createMock(OrderEmailManagerInterface::class);
        $this->paymentLinkEmailManager = $this->createMock(PaymentLinkEmailManagerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->checkout = new DraftOrderCheckout(
            $this->stateMachine,
            $this->createMock(ValidatorInterface::class),
            $this->entityManager,
            $this->integrityChecker,
            $this->rowLock,
            $this->orderEmailManager,
            $this->paymentLinkEmailManager,
            $this->eventDispatcher,
            $this->createMock(TranslatorInterface::class),
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

        $this->connection->expects(self::once())->method('commit');
        $result = $this->checkout->complete($creation, true, true);

        self::assertTrue($result->isPlaced());
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

        self::assertTrue($result->isPlaced());
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

    public function testAChangedPromotionStopsThePlacement(): void
    {
        $creation = $this->creation();
        $promotion = new Promotion();
        $promotion->setName('Spring');
        $this->integrityChecker->method('check')->willReturn($promotion);
        $this->stateMachine->expects(self::never())->method('apply');
        $this->orderEmailManager->expects(self::never())->method('sendConfirmationEmail');
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        $result = $this->checkout->complete($creation, true, false);

        self::assertSame(CompletionResult::CHANGED, $result->status);
        self::assertSame('Spring', $result->removedPromotion);
        self::assertNull($creation->getCompletedAt());
    }

    public function testADraftPlacedOrDeletedConcurrentlyIsNotPlacedTwice(): void
    {
        $rowLock = $this->createMock(OrderRowLockInterface::class);
        $rowLock->method('lockDraft')->willReturn(false);
        $checkout = new DraftOrderCheckout(
            $this->stateMachine,
            $this->createMock(ValidatorInterface::class),
            $this->entityManager,
            $this->integrityChecker,
            $rowLock,
            $this->orderEmailManager,
            $this->paymentLinkEmailManager,
            $this->eventDispatcher,
            $this->createMock(TranslatorInterface::class),
            new MockClock(),
            $this->logger,
        );
        $this->stateMachine->expects(self::never())->method('apply');
        $this->connection->expects(self::once())->method('rollBack');
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        self::assertSame(CompletionResult::NOT_A_DRAFT, $checkout->complete($this->creation(), true, false)->status);
    }

    public function testAFailingListenerDoesNotTurnAPlacedOrderIntoAnError(): void
    {
        $this->eventDispatcher->method('dispatch')->willThrowException(new \RuntimeException('ERP down'));
        $this->logger->expects(self::once())->method('error');

        self::assertTrue($this->checkout->complete($this->creation(), false, false)->isPlaced());
    }

    public function testAnEmptyOrderIsNotReady(): void
    {
        $creation = $this->creation();
        $order = $creation->getOrder();
        self::assertNotNull($order);
        foreach ($order->getItems() as $item) {
            $order->removeItem($item);
        }

        self::assertFalse($this->checkout->isReadyToComplete($order));
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
        $order->addItem(new OrderItem());
        $order->setCheckoutState(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $creation = new AdminOrderCreation();
        $creation->setOrder($order);

        return $creation;
    }
}
