<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

/**
 * Whether a delegation is within its parent, on every dimension (06 §5.2, M3-1): what issuing it
 * checks, and every use checks again for the whole chain. Its reason when it isn't, else null.
 *
 * @api
 *
 * @experimental
 */
final readonly class Attenuation
{
    public function __construct(
        private Purposes $purposes = new Purposes(),
    ) {
    }

    /**
     * Whether the records disagree with each other: a child that doesn't name the parent, isn't passed
     * on by its delegate, or has another root or depth. Unknown at a use, never a known refusal.
     */
    public function inconsistent(Delegation $child, Delegation $parent): ?string
    {
        return match (true) {
            !$child->realm->equals($parent->realm) => 'another realm',
            null === $child->parent || !$child->parent->equals($parent->id) => 'another parent',
            !$child->delegator->equals($parent->delegate) => 'a delegator the parent wasn\'t given to',
            !$child->root->equals($parent->root) => 'another root',
            $child->depth !== $parent->depth + 1 => 'a depth that isn\'t its parent\'s + 1',
            default => null,
        };
    }

    /**
     * Whether it's wider than its parent on any dimension: a known refusal.
     */
    public function outside(Delegation $child, Delegation $parent): ?string
    {
        return match (true) {
            [] !== array_diff($child->actions, $parent->redelegable) => 'actions its parent may not pass on',
            [] !== array_diff($child->redelegable, $child->actions) || [] !== array_diff($child->redelegable, $parent->redelegable) => 'more to pass on than its parent allows',
            $parent->remainingDepth < 1 || $child->remainingDepth > $parent->remainingDepth - 1 => 'deeper than its parent allows',
            !$child->resources->within($parent->resources) => 'resources outside its parent\'s',
            !$this->purposes->within($child->purpose, $parent->purpose) => 'a purpose outside its parent\'s',
            $child->notBefore < $parent->notBefore || $child->expiresAt > $parent->expiresAt => 'a window outside its parent\'s',
            null !== $parent->execution && $child->execution !== $parent->execution => 'another task than its parent\'s',
            !self::asStrict($child, $parent) => 'a weaker proof than its parent asks',
            default => null,
        };
    }

    /**
     * The child asks at least the parent's proof: as recent or more, and every kind of factor it asks.
     */
    private static function asStrict(Delegation $child, Delegation $parent): bool
    {
        if (null === $parent->stepUp) {
            return true;
        }

        return null !== $child->stepUp
            && !$child->stepUp->within->isLongerThan($parent->stepUp->within)
            && ($child->stepUp->multiFactor || !$parent->stepUp->multiFactor)
            && ($child->stepUp->phishingResistant || !$parent->stepUp->phishingResistant);
    }
}
