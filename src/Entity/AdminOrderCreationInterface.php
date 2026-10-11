<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Entity;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * Marks an order created from the back office: while it is a draft (cart state, not completed)
 * it is invisible to the shop, and once completed it remembers which administrator placed it.
 */
interface AdminOrderCreationInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getOrder(): ?OrderInterface;

    public function setOrder(OrderInterface $order): void;

    public function getAdminUser(): ?AdminUserInterface;

    /** Keeps a snapshot of the administrator's name, shown even after the account is deleted. */
    public function setAdminUser(?AdminUserInterface $adminUser): void;

    public function getAdminName(): ?string;

    public function getSourceOrder(): ?OrderInterface;

    public function setSourceOrder(?OrderInterface $sourceOrder): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function setCreatedAt(\DateTimeImmutable $createdAt): void;

    public function getCompletedAt(): ?\DateTimeImmutable;

    public function setCompletedAt(?\DateTimeImmutable $completedAt): void;

    public function isDraft(): bool;

    public function isConfirmationEmailSent(): bool;

    public function setConfirmationEmailSent(bool $confirmationEmailSent): void;

    public function getPaymentLinkSentAt(): ?\DateTimeImmutable;

    public function setPaymentLinkSentAt(?\DateTimeImmutable $paymentLinkSentAt): void;
}
