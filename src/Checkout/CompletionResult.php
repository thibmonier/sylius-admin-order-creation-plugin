<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Checkout;

final class CompletionResult
{
    public function __construct(
        public readonly bool $confirmationEmailSent,
        public readonly bool $paymentLinkSent,
        public readonly bool $emailFailed,
    ) {
    }
}
