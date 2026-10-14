<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Security;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\DraftVoter;

final class DraftVoterTest extends TestCase
{
    public function testDraftsAreSharedByDefault(): void
    {
        $voter = new DraftVoter(false);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($this->admin(2)), $this->draft($this->admin(1)), [DraftVoter::ACCESS]));
    }

    public function testOnlyTheAuthorOpensADraftWhenRestricted(): void
    {
        $voter = new DraftVoter(true);
        $draft = $this->draft($this->admin(1));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($this->admin(1)), $draft, [DraftVoter::ACCESS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($this->admin(2)), $draft, [DraftVoter::ACCESS]));
    }

    public function testADraftWithoutAuthorStaysOpenWhenRestricted(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, (new DraftVoter(true))->vote($this->token($this->admin(2)), $this->draft(null), [DraftVoter::ACCESS]));
    }

    public function testOnlyAdministratorsOpenDrafts(): void
    {
        $token = $this->token($this->createMock(ShopUserInterface::class));

        self::assertSame(VoterInterface::ACCESS_DENIED, (new DraftVoter(false))->vote($token, $this->draft(null), [DraftVoter::ACCESS]));
    }

    public function testItAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = new DraftVoter(true);
        $token = $this->token($this->admin(1));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $this->draft(null), ['ROLE_ADMINISTRATION_ACCESS']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [DraftVoter::ACCESS]));
    }

    private function admin(int $id): AdminUserInterface
    {
        $admin = $this->createMock(AdminUserInterface::class);
        $admin->method('getId')->willReturn($id);

        return $admin;
    }

    private function draft(?AdminUserInterface $author): AdminOrderCreation
    {
        $draft = new AdminOrderCreation();
        $draft->setAdminUser($author);

        return $draft;
    }

    private function token(object $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
