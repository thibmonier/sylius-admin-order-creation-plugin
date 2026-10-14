<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Entity;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;

class AdminOrderCreation implements AdminOrderCreationInterface
{
    protected ?int $id = null;

    protected ?OrderInterface $order = null;

    protected ?AdminUserInterface $adminUser = null;

    protected ?string $adminName = null;

    protected ?OrderInterface $sourceOrder = null;

    protected \DateTimeImmutable $createdAt;

    protected ?\DateTimeImmutable $completedAt = null;

    protected ?string $manualDiscountReason = null;

    protected bool $confirmationEmailSent = false;

    protected ?\DateTimeImmutable $paymentLinkSentAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getAdminUser(): ?AdminUserInterface
    {
        return $this->adminUser;
    }

    public function setAdminUser(?AdminUserInterface $adminUser): void
    {
        $this->adminUser = $adminUser;
        if (!$adminUser instanceof \Sylius\Component\Core\Model\AdminUserInterface) {
            return;
        }

        $name = trim(sprintf('%s %s', $adminUser->getFirstName() ?? '', $adminUser->getLastName() ?? ''));
        $this->adminName = '' !== $name ? $name : $adminUser->getUsername();
    }

    public function getAdminName(): ?string
    {
        return $this->adminName;
    }

    public function getSourceOrder(): ?OrderInterface
    {
        return $this->sourceOrder;
    }

    public function setSourceOrder(?OrderInterface $sourceOrder): void
    {
        $this->sourceOrder = $sourceOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): void
    {
        $this->completedAt = $completedAt;
    }

    public function isDraft(): bool
    {
        return !$this->completedAt instanceof \DateTimeImmutable && OrderInterface::STATE_CART === $this->order?->getState();
    }

    public function getManualDiscountReason(): ?string
    {
        return $this->manualDiscountReason;
    }

    public function setManualDiscountReason(?string $manualDiscountReason): void
    {
        $this->manualDiscountReason = $manualDiscountReason;
    }

    public function isConfirmationEmailSent(): bool
    {
        return $this->confirmationEmailSent;
    }

    public function setConfirmationEmailSent(bool $confirmationEmailSent): void
    {
        $this->confirmationEmailSent = $confirmationEmailSent;
    }

    public function getPaymentLinkSentAt(): ?\DateTimeImmutable
    {
        return $this->paymentLinkSentAt;
    }

    public function setPaymentLinkSentAt(?\DateTimeImmutable $paymentLinkSentAt): void
    {
        $this->paymentLinkSentAt = $paymentLinkSentAt;
    }
}
