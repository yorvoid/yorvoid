<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Principal\PrincipalStore;

/**
 * Validates a delegation's chain now (06 §5.3): every link active, inside its window, within its parent,
 * its delegator and delegate active principals; the chain acyclic, no deeper than the limit, and ending
 * at the root's own delegation. What a use checks, and what passing a delegation on checks of its parent.
 * The root's authority now is the evaluator's to decide.
 *
 * @api
 *
 * @experimental
 */
final readonly class Chains
{
    public function __construct(
        private DelegationStore $store,
        private PrincipalStore $principals,
        private Attenuation $attenuation = new Attenuation(),
        private DelegationLimits $limits = new DelegationLimits(),
    ) {
    }

    public function check(Delegation $leaf, \DateTimeImmutable $now): ChainCheck
    {
        $links = [];
        $seen = [];
        /** @var array<string, bool> $active Each principal read once: a link's delegate is the next one's delegator */
        $active = [];

        for ($link = $leaf;; $link = $parent) {
            $links[] = $link;

            if (isset($seen[(string) $link->id]) || \count($links) > $this->limits->depth) {
                return ChainCheck::unknown(isset($seen[(string) $link->id]) ? 'a cycle' : 'deeper than the limit', $links);
            }

            $seen[(string) $link->id] = true;

            if (!$link->isActive()) {
                return ChainCheck::invalid(\sprintf('the delegation %s is revoked', $link->id), $links);
            }

            if (!$link->isCurrentAt($now)) {
                return ChainCheck::invalid(\sprintf('the delegation %s isn\'t current', $link->id), $links);
            }

            foreach ([$link->delegator, $link->delegate] as $principal) {
                if (!($active[(string) $principal] ??= true === $this->principals->get($link->realm, $principal)?->isActive())) {
                    return ChainCheck::invalid(\sprintf('the principal %s isn\'t active', $principal), $links);
                }
            }

            if (null === $link->parent) {
                return $link->delegator->equals($link->root) && 0 === $link->depth
                    ? ChainCheck::valid($links)
                    : ChainCheck::unknown(\sprintf('the delegation %s has no parent but isn\'t its root\'s', $link->id), $links);
            }

            $parent = $this->store->get($link->realm, $link->parent);

            if (null === $parent) {
                return ChainCheck::unknown(\sprintf('the delegation %s\'s parent is missing', $link->id), $links);
            }

            $inconsistent = $this->attenuation->inconsistent($link, $parent);

            if (null !== $inconsistent) {
                return ChainCheck::unknown(\sprintf('the delegation %s has %s', $link->id, $inconsistent), $links);
            }

            $outside = $this->attenuation->outside($link, $parent);

            if (null !== $outside) {
                return ChainCheck::invalid(\sprintf('the delegation %s has %s', $link->id, $outside), $links);
            }
        }
    }
}
