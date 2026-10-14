<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Security;

use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;

/**
 * Access to a draft (see, edit, delete). Drafts are shared between administrators unless
 * restrict_drafts_to_owner is set; then only their author can open them, and drafts whose
 * author was deleted stay open to everyone. Applications can grant more (e.g. to a manager
 * role) with a voter of their own: the default strategy grants access when one voter does.
 *
 * @extends Voter<string, AdminOrderCreationInterface>
 */
final class DraftVoter extends Voter
{
    public const ACCESS = 'TMONIER_ADMIN_ORDER_DRAFT_ACCESS';

    public function __construct(private readonly bool $restrictToOwner)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute && $subject instanceof AdminOrderCreationInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AdminUserInterface) {
            return false;
        }

        $author = $subject->getAdminUser();
        if (!$this->restrictToOwner || !$author instanceof AdminUserInterface) {
            return true;
        }

        return null !== $author->getId() && $author->getId() === $user->getId();
    }
}
