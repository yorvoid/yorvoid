<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * An application's own voter whose supports() is loose: everything of Acme's, YorvoID's declared
 * abilities included, which it grants.
 *
 * @extends Voter<string, mixed>
 */
final class GrantsAcme extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, 'acme');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $vote?->addReason(\sprintf('Acme grants %s.', $attribute));

        return true;
    }
}
