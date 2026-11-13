<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * A voter that answers the same to everything and says what it was asked.
 */
final class RecordingVoter implements VoterInterface
{
    /** @var VoterInterface::ACCESS_* */
    public int $result = VoterInterface::ACCESS_ABSTAIN;

    /** @var list<array<mixed>> */
    public array $asked = [];

    /** @var list<bool> */
    public array $withVote = [];

    /**
     * @param array<mixed> $attributes
     */
    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        $this->asked[] = $attributes;
        $this->withVote[] = null !== $vote;
        $vote?->addReason(\sprintf('Voted on %s.', implode(', ', array_map(static fn (mixed $attribute): string => \is_string($attribute) ? $attribute : get_debug_type($attribute), $attributes))));

        return $this->result;
    }
}
