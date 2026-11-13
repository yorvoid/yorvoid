<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * An application's own decorator of one of Symfony's voters, which grants everything of Acme's,
 * YorvoID's declared abilities included, and leaves the rest to the voter it decorates.
 */
final readonly class DecoratesAuthenticatedVoter implements VoterInterface
{
    public function __construct(
        private VoterInterface $inner,
    ) {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        foreach ($attributes as $attribute) {
            if (\is_string($attribute) && str_starts_with($attribute, 'acme')) {
                return self::ACCESS_GRANTED;
            }
        }

        return $this->inner->vote($token, $subject, $attributes, $vote);
    }
}
