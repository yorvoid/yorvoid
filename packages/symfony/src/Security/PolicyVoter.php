<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Realm;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;

/**
 * Symfony's isGranted() for every declared ability, decided by YorvoID's evaluator in the bundle's realm:
 * the request's session acts, or someone not signed in. The subject says which resource it's about: a
 * ResourceRef, an object that implements ConcernsResource, [ResourceRef, object], or none (no subject, the
 * Request access_control passes, an object that implements ConcernsNoResource); anything else is refused
 * (SubjectOf). Only a Permit grants, and no other voter can grant a declared ability (AbstainOnYorvoIDAbilities).
 *
 * ```php
 * $this->denyAccessUnlessGranted('acme.pages.edit', new ResourceRef('page', $id));
 * $this->denyAccessUnlessGranted('acme.pages.edit', $page); // a Page that implements ConcernsResource
 * ```
 *
 * @extends Voter<string, mixed>
 *
 * @internal The bundle registers it
 */
final class PolicyVoter extends Voter
{
    public function __construct(
        private readonly Policy $policy,
        private readonly PolicyCatalogue $catalogue,
        private readonly CurrentSession $session,
        private readonly Realm $realm,
        private readonly RequestOrigins $origins,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->catalogue->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        [$resource, $object] = SubjectOf::isGranted($subject, $attribute);
        $decision = $this->policy->decide($this->session->get() ?? new Anonymous(), $attribute, $this->realm, $resource, $this->origins->of(), $object);
        // The reason stays in the decision log: Symfony shows the vote's in its exception's message, and which
        // rule refused would describe the policy (L-9). A recorded decision is found by its ID.
        $vote?->addReason(null === $decision->hash ? \sprintf('YorvoID: %s.', $decision->verdict->name) : \sprintf('YorvoID: %s (decision %s).', $decision->verdict->name, $decision->id));

        return $decision->permits();
    }
}
