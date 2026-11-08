<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Realm;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;

/**
 * Symfony's isGranted() for every declared ability, decided by YorvoID's evaluator in the bundle's realm:
 * the request's session acts, or someone not signed in. A ResourceRef is the resource; any other object
 * is the request's subject, for rules and resolvers, and its resource the one it concerns (ConcernsResource),
 * if any. Only a Permit grants.
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
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->catalogue->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $resource = $subject instanceof ResourceRef ? $subject : null;
        $object = \is_object($subject) && null === $resource ? $subject : null;
        $decision = $this->policy->decide($this->session->get() ?? new Anonymous(), $attribute, $this->realm, $resource, new Origin(OriginKind::Web), $object);
        $vote?->addReason($decision->reason);

        return $decision->permits();
    }
}
