<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

final class CompletionResult
{
    public const PLACED = 'placed';

    /** The recalculation changed the promotions or the total: the admin must review the summary. */
    public const CHANGED = 'changed';

    /** A concurrent request placed or deleted the draft first. */
    public const NOT_A_DRAFT = 'not_a_draft';

    private function __construct(
        public readonly string $status,
        public readonly bool $confirmationEmailSent = false,
        public readonly bool $paymentLinkSent = false,
        public readonly bool $emailFailed = false,
        public readonly ?string $removedPromotion = null,
    ) {
    }

    public static function placed(bool $confirmationEmailSent, bool $paymentLinkSent, bool $emailFailed): self
    {
        return new self(self::PLACED, $confirmationEmailSent, $paymentLinkSent, $emailFailed);
    }

    public static function changed(?string $removedPromotion): self
    {
        return new self(self::CHANGED, removedPromotion: $removedPromotion);
    }

    public static function notADraft(): self
    {
        return new self(self::NOT_A_DRAFT);
    }

    public function isPlaced(): bool
    {
        return self::PLACED === $this->status;
    }
}
