<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\AttenuatedByParent;
use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\DeclaredEffects;
use YorvoID\Core\Authorization\Effect;
use YorvoID\Core\Authorization\EffectPart;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\RevokesDelegation;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operator;
use YorvoID\Relations\Subject;
use YorvoID\Relations\TooDeep;

/**
 * Nobody gives or takes what they don't hold themselves (M2-13): each change is weighed by what it
 * gives and takes, to anyone, directly or through what follows from it (its declared effect), and refused when they
 * couldn't give or take that directly: a role whose abilities they don't all have, a super role unless
 * they have one, an ability they lack. Nobody sets their own security attributes either: what they
 * decide is the application's rules'.
 *
 * Managing roles doesn't make anyone more than they are. The host's operator is the final authority,
 * and isn't held to it.
 *
 * @api
 */
final readonly class NoEscalation implements ForbidElement, ChecksCatalogue
{
    public const string NAME = 'yorvoid.policy.no_escalation';

    private DeclaredEffects $effects;

    /**
     * @param DeclaredEffects|null $effects What the changes give and take: the policy's own if not given
     */
    public function __construct(
        private Roles $roles,
        ?DeclaredEffects $effects = null,
    ) {
        $this->effects = $effects ?? PolicyEffects::shared($roles);
    }

    /**
     * Every change of authority it may meet has its effect declared.
     */
    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        $this->effects->check($catalogue);
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * A change of authority that gives or takes anything, or whose effect isn't declared.
     */
    public function matches(Request $request): bool
    {
        // A re-delegation is within its parent, which its handler checks: what it gives is the parent's, not
        // the actor's own roles'. Only when it names one: a root delegation is weighed as any change.
        // Nor a revocation of delegations: who may revoke is the chain's, and revoking is never held back.
        $attenuated = ($request->command instanceof AttenuatedByParent && null !== $request->command->attenuatedBy()) || $request->command instanceof RevokesDelegation;

        return !$request->actor instanceof Operator
            && !$attenuated
            && $request->command instanceof ChangesAuthority
            && (!($effect = $this->effects->effectOf($request)) instanceof Effect || $effect->changes());
    }

    public function evaluate(Request $request): Truth
    {
        if (!$request->command instanceof ChangesAuthority) {
            return Truth::fails('It changes no authority.');
        }

        $effect = $this->effects->effectOf($request);

        // A change of authority whose effect nothing declares is never taken for one that gives nothing (D-51).
        if (null === $effect) {
            return Truth::unknown(IndeterminateReason::EvaluationError, \sprintf('Nothing declares what %s gives and takes.', $request->name()));
        }

        if (null !== $effect->attribute) {
            return null !== $request->actor->principalId && $request->actor->principalId->equals($effect->attribute->principal)
                ? Truth::holds('Nobody sets their own security attributes.')
                : Truth::fails('They set someone else\'s.');
        }

        foreach ([...$effect->gives, ...$effect->takes] as $part) {
            $abilities = array_values(array_diff($part->abilities, $this->givenByRelations($request, $part)));
            $truth = $this->roles(null === $part->on ? $request : $request->on($part->on), $part->roles, $abilities, $part->byRelation);

            if (TruthValue::Fails !== $truth->value) {
                return $truth;
            }
        }

        return Truth::fails('They hold everything they change.');
    }

    /**
     * The part's abilities its relations' definitions give the actor on every resource of its type they
     * have one of them to: those are held wherever the part gives them. Checked here, never taken from the
     * declaration: a relation that gives none of them, or someone else's, leaves them to be weighed.
     *
     * @return list<string>
     */
    private function givenByRelations(Request $request, EffectPart $part): array
    {
        $schema = $this->roles->relations()?->schema();
        $principal = $request->actor->principalId;

        if ([] === $part->viaRelations || null === $part->onType || null === $schema || null === $principal || true !== $part->subject?->equals(new ResourceRef('principal', (string) $principal))) {
            return [];
        }

        $given = [];

        foreach ($part->viaRelations as $relation) {
            array_push($given, ...$schema->abilitiesGivenBy($part->onType, $relation));
        }

        return array_values(array_intersect($part->abilities, $given));
    }

    /**
     * Each role's abilities they hold, and a super role only if they have one; and the abilities.
     *
     * @param list<string> $roles
     * @param list<string> $abilities
     * @param bool         $byRelation Whether a relation to the request's resource holds an ability, or only a role does
     */
    private function roles(Request $request, array $roles, array $abilities, bool $byRelation): Truth
    {
        foreach ($roles as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super && !$this->hasSuperRole($request)) {
                return Truth::holds(\sprintf('"%s" is a super role: only someone with one gives it or takes it.', $role));
            }

            array_push($abilities, ...$this->roles->abilitiesOf($request->realm, $role));
        }

        return $this->abilities($request, $abilities, $byRelation);
    }

    /**
     * Every ability they hold, through a role or, unless only a role will do, a relation to the
     * request's resource.
     *
     * @param list<string> $abilities
     */
    private function abilities(Request $request, array $abilities, bool $byRelation): Truth
    {
        $missing = array_filter($this->roles->missing($request, array_unique($abilities)), fn (string $ability): bool => !$byRelation || !$this->related($request, $ability));

        if ([] === $missing) {
            return Truth::fails('They hold everything they change.');
        }

        $where = null === $request->resource ? '' : \sprintf(' on %s', $request->resource);

        return Truth::holds(\sprintf('They\'d change what they don\'t hold themselves%s: %s.', $where, implode(', ', $missing)));
    }

    /**
     * Whether a relation to the request's resource gives them the ability.
     */
    private function related(Request $request, string $ability): bool
    {
        $relations = $this->roles->relations();
        $principal = $request->actor->principalId;

        if (null === $relations || null === $request->resource || null === $principal) {
            return false;
        }

        foreach ($relations->schema()->granting($request->resource->type, $ability) as $relation) {
            if ($relations->check($request->realm, $request->resource, $relation, Subject::principal($principal)) ?? throw new TooDeep(\sprintf('The relations to %s', $request->resource))) {
                return true;
            }
        }

        return false;
    }

    private function hasSuperRole(Request $request): bool
    {
        foreach ($this->roles->rolesOf($request) as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super) {
                return true;
            }
        }

        return false;
    }
}
