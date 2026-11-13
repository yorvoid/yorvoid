<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use YorvoID\Policy\PolicyCatalogue;

/**
 * Another voter, as the bundle hands it to Symfony: it can't grant a declared ability, which YorvoID
 * alone decides (M-7). Its refusals count where the strategy weighs them, so an application's own
 * restriction stays; and it decides everything else as before. A grant for a declared ability is
 * asked again without it, and abstains when nothing else was asked.
 *
 * @internal The bundle wraps every voter but PolicyVoter in it
 */
final readonly class AbstainOnYorvoIDAbilities implements CacheableVoterInterface
{
    public function __construct(
        public VoterInterface $voter,
        private PolicyCatalogue $catalogue,
    ) {
    }

    /**
     * @param array<mixed> $attributes
     */
    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        $asked = null === $vote ? null : new Vote();
        $result = $this->voter->vote($token, $subject, $attributes, $asked);
        $others = array_values(array_filter($attributes, fn (mixed $attribute): bool => !\is_string($attribute) || !$this->catalogue->has($attribute)));

        if (self::ACCESS_GRANTED === $result && \count($others) < \count($attributes)) {
            if ([] === $others) {
                return self::ACCESS_ABSTAIN;
            }

            $asked = null === $vote ? null : new Vote();
            $result = $this->voter->vote($token, $subject, $others, $asked);
        }

        foreach ($asked->reasons ?? [] as $reason) {
            // Asked with a vote only when given one.
            \assert(null !== $vote);
            $vote->addReason($reason);
        }

        return $result;
    }

    public function supportsAttribute(string $attribute): bool
    {
        return !$this->voter instanceof CacheableVoterInterface || $this->voter->supportsAttribute($attribute);
    }

    public function supportsType(string $subjectType): bool
    {
        return !$this->voter instanceof CacheableVoterInterface || $this->voter->supportsType($subjectType);
    }
}
