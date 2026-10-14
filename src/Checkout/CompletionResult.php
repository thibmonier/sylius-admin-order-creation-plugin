<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

final readonly class CompletionResult
{
    public const PLACED = 'placed';

    /** The recalculation changed the promotions or the total: the admin must review the summary. */
    public const CHANGED = 'changed';

    /** A concurrent request placed or deleted the draft first. */
    public const NOT_A_DRAFT = 'not_a_draft';

    private function __construct(
        public string $status,
        public bool $confirmationEmailSent = false,
        public bool $paymentLinkSent = false,
        public bool $emailFailed = false,
        public ?string $removedPromotion = null,
        /** The order is placed, but saving the e-mail flags or a listener of the event failed (logged). */
        public bool $postCompletionError = false,
    ) {
    }

    public static function placed(bool $confirmationEmailSent, bool $paymentLinkSent, bool $emailFailed, bool $postCompletionError = false): self
    {
        return new self(self::PLACED, $confirmationEmailSent, $paymentLinkSent, $emailFailed, null, $postCompletionError);
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
