<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Price overrides and manual discounts are allowed when enabled in the configuration and the
 * administrator has the configured role.
 *
 * @extends Voter<string, mixed>
 */
final class OrderAdjustmentVoter extends Voter
{
    public const PRICE_OVERRIDE = 'TMONIER_ADMIN_ORDER_PRICE_OVERRIDE';

    public const MANUAL_DISCOUNT = 'TMONIER_ADMIN_ORDER_MANUAL_DISCOUNT';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly bool $priceOverrideAllowed,
        private readonly bool $manualDiscountAllowed,
        private readonly string $requiredRole,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::PRICE_OVERRIDE === $attribute || self::MANUAL_DISCOUNT === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $enabled = self::PRICE_OVERRIDE === $attribute ? $this->priceOverrideAllowed : $this->manualDiscountAllowed;
        if (!$enabled) {
            return false;
        }

        return $this->accessDecisionManager->decide($token, [$this->requiredRole]);
    }
}
