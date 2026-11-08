<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Explanation;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Realm;

/**
 * Asks whether an actor holds an ability, for what isn't one of YorvoID's operations: an application's
 * own "edit this page" or "see this report". The evaluator decides as it does for operations, with the
 * roles, the rules and every other element.
 *
 * ```php
 * if (!$policy->allows($session, 'acme.pages.edit', $realm, new ResourceRef('page', $id), $origin, $page)) { ... }
 * ```
 *
 * The subject, the application's own object (the page), is for rules and attribute resolvers to read,
 * e.g. its author for "edit your own". It's never recorded, logged or cached.
 *
 * @api
 */
final readonly class Policy
{
    /**
     * @param Roles|null $roles What why-not reads the roles and relations that would permit from
     */
    public function __construct(
        private Evaluator $evaluator,
        private ?Roles $roles = null,
    ) {
    }

    /**
     * @param object|null $subject The application's own object it's about: the resource is the one it
     *                             concerns, if not given. One with neither is about no resource, so
     *                             only roles on the whole realm count
     *
     * @throws IntegratorError When the subject concerns another resource than the one given
     */
    public function decide(Actor $actor, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin, ?object $subject = null): Decision
    {
        return $this->evaluator->decideAbility($actor, $ability, $realm, $resource, $origin, $subject);
    }

    /**
     * Whether the actor holds the ability on each of the resources, e.g. every page of a list: decided
     * one by one, with what the roles and relations need read at once, so the queries don't grow with
     * the number of resources (AUZ-030, AUZ-032). Each is a resource, a subject that concerns one, or a
     * resource with its subject:
     *
     * ```php
     * $policy->decideMany($session, 'acme.pages.edit', $realm, array_map(fn (Page $page): array => [new ResourceRef('page', $page->id), $page], $pages), $origin);
     * ```
     *
     * @param list<ResourceRef|ConcernsResource|array{ResourceRef, object}> $resources
     *
     * @throws IntegratorError When one is none of these, or its subject concerns another resource
     */
    public function decideMany(Actor $actor, string $ability, Realm $realm, array $resources, Origin $origin): Decisions
    {
        $asked = array_map(static fn (mixed $one): array => self::asked($one, $ability), $resources);
        $decisions = $this->evaluator->decideAbilities($actor, $ability, $realm, $asked, $origin);

        return new Decisions(array_map(static fn (array $one, Decision $decision): array => [$one[0], $decision], $asked, $decisions));
    }

    /**
     * Why the actor may not do it, or may (AUZ-021): the decision, its explanation, and what would permit
     * it. Actors ask about themselves; asking about someone else takes "yorvoid.policy.explain", and
     * that decision is recorded whatever it is.
     *
     * ```php
     * $why = $policy->whyNot($session, $member, 'acme.pages.publish', $realm, new ResourceRef('page', $id), $origin);
     * ```
     *
     * @param Actor $asking Who asks
     * @param Actor $about  Who it's about: the same, or another in the realm
     *
     * @throws NotPermitted    When asking about someone else without "yorvoid.policy.explain"
     * @throws IntegratorError When the policy has no roles to answer from, or the subject concerns another resource
     */
    public function whyNot(Actor $asking, Actor $about, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin, ?object $subject = null): WhyNot
    {
        $roles = $this->roles ?? throw new IntegratorError('Why-not reads the roles, and the policy has none.', 'Give Policy the Roles it decides with.', 'concepts/authorization.md#why-not');
        $self = $asking === $about || (null !== $asking->principalId && null !== $about->principalId && $asking->principalId->equals($about->principalId) && null !== $asking->realm && null !== $about->realm && $asking->realm->equals($about->realm));

        if (!$self) {
            $explain = $this->decide($asking, YorvoIDDeclarations::POLICY_EXPLAIN, $realm, null, $origin);

            if (!$explain->permits()) {
                throw new NotPermitted(YorvoIDDeclarations::POLICY_EXPLAIN, $explain);
            }
        }

        $decision = $this->decide($about, $ability, $realm, $resource, $origin, $subject);
        $declared = $roles->ability($ability) ?? throw new UnknownAbility($ability);
        // A subject that concerns a resource concerns the one given, if one is: decide() refused it otherwise.
        $type = ($subject instanceof ConcernsResource ? $subject->resource() : $resource)?->type;
        $super = $declared->strict ? [] : array_keys(array_filter($roles->roles($realm), static fn (string $label, string $name): bool => true === $roles->role($realm, $name)?->super, \ARRAY_FILTER_USE_BOTH));

        return new WhyNot(
            $decision,
            Explanation::of($decision),
            $decision->remedies,
            $roles->rolesWith($realm, $ability),
            $super,
            null === $type ? [] : $roles->relations()?->schema()->granting($type, $ability) ?? [],
            array_values(array_map(static fn (Reason $reason): string => $reason->source, array_filter($decision->reasons, static fn (Reason $reason): bool => 'forbid' === $reason->effect && TruthValue::Holds === $reason->truth->value))),
        );
    }

    /**
     * What decideMany() was given as a resource and its subject: checked, as PHPDoc types aren't.
     *
     * @return array{ResourceRef, object|null}
     *
     * @throws IntegratorError
     */
    private static function asked(mixed $one, string $ability): array
    {
        return match (true) {
            $one instanceof ResourceRef => [$one, null],
            $one instanceof ConcernsResource => [$one->resource(), $one],
            \is_array($one) && ($one[0] ?? null) instanceof ResourceRef && \is_object($one[1] ?? null) => [$one[0], $one[1]],
            default => throw new IntegratorError(\sprintf('decideMany() was given %s for "%s".', get_debug_type($one), $ability), 'Give each a ResourceRef, a subject that implements ConcernsResource, or [ResourceRef, subject].', 'concepts/authorization.md#many-at-once'),
        };
    }

    /**
     * Whether the decision is a Permit, and only that.
     *
     * @throws IntegratorError When the subject concerns another resource than the one given
     */
    public function allows(Actor $actor, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin, ?object $subject = null): bool
    {
        return $this->decide($actor, $ability, $realm, $resource, $origin, $subject)->permits();
    }
}
