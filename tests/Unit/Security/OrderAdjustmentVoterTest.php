<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\OrderAdjustmentVoter;

final class OrderAdjustmentVoterTest extends TestCase
{
    public function testItGrantsWhenEnabledAndTheAdministratorHasTheRole(): void
    {
        $voter = $this->voter(true, true, true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::PRICE_OVERRIDE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::MANUAL_DISCOUNT]));
    }

    public function testItDeniesWithoutTheRole(): void
    {
        $voter = $this->voter(true, true, false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::PRICE_OVERRIDE]));
    }

    public function testEachFeatureCanBeDisabled(): void
    {
        $voter = $this->voter(false, true, true);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::PRICE_OVERRIDE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::MANUAL_DISCOUNT]));

        $voter = $this->voter(true, false, true);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::PRICE_OVERRIDE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createMock(TokenInterface::class), null, [OrderAdjustmentVoter::MANUAL_DISCOUNT]));
    }

    public function testItAbstainsOnOtherAttributes(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter(true, true, true)->vote($this->createMock(TokenInterface::class), null, ['ROLE_ADMINISTRATION_ACCESS']));
    }

    private function voter(bool $priceOverride, bool $manualDiscount, bool $hasRole): OrderAdjustmentVoter
    {
        $decisionManager = $this->createMock(AccessDecisionManagerInterface::class);
        $decisionManager->method('decide')->willReturnCallback(static fn (TokenInterface $token, array $attributes): bool => $hasRole && ['ROLE_PHONE_DESK'] === $attributes);

        return new OrderAdjustmentVoter($decisionManager, $priceOverride, $manualDiscount, 'ROLE_PHONE_DESK');
    }
}
