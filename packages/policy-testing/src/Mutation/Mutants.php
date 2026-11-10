<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Policy\Ability;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Role;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Rule\RuleElements;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\RelationPermit;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\ResourceType;

/**
 * The mutants of a policy (AUZ-045), and the switch that turns one on. The policy under test is built
 * through its seams: the elements, the authority check, the policy and relation stores, the catalogue
 * and the relation schema. In Symfony the bundle wires them; without it, production's function passes
 * what it builds through them:
 *
 * ```php
 * $evaluator = Evaluator::builtIn($mutants->authority($authority), elements: $mutants->elements($elements), ...);
 * ```
 *
 * The mutants come from what the policy declares, never from what its cases do: a mutant the cases never
 * reach is reported as not exercised, a gap to fill or an equivalent to ignore.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final class Mutants
{
    private ?Mutant $active = null;

    private ?PolicyCatalogue $catalogue = null;

    private ?RelationSchema $schema = null;

    /** @var array<string, 'permit'|'forbid'|'rule-permit'> The application's own elements, by name */
    private array $elements = [];

    /** @var list<string> Elements the cases saw holding */
    private array $held = [];

    /** @var list<string> Elements the cases saw unknown */
    private array $unknown = [];

    /** @var list<string> What the policy was built through */
    private array $wired = [];

    public function catalogue(PolicyCatalogue $catalogue): PolicyCatalogue
    {
        $this->wired[] = 'catalogue';

        return $this->catalogue = $catalogue;
    }

    /**
     * The schema, as the mutant on changes it: without one implication, a copy rebuilt from the
     * declared types, the schema itself being final.
     */
    public function schema(RelationSchema $schema): RelationSchema
    {
        $this->wired[] = 'schema';
        $this->schema = $schema;
        $active = $this->active;

        return MutationOperator::DropImplication === $active?->operator ? self::without($schema, (string) $active->subject, (string) $active->role) : $schema;
    }

    /**
     * A copy of the schema without one implication: "type#relation" no longer implied by $implied.
     */
    private static function without(RelationSchema $schema, string $relation, string $implied): RelationSchema
    {
        [$type, $name] = explode('#', $relation);
        $types = [];

        foreach ($schema->types() as $declared) {
            $relations = [];

            foreach ($declared->relations as $definition) {
                $relations[] = $declared->name === $type && $definition->name === $name
                    ? new RelationDefinition($definition->name, array_values(array_diff($definition->impliedBy, [$implied])), $definition->fromParents, $definition->grants)
                    : $definition;
            }

            $types[] = new ResourceType($declared->name, $relations);
        }

        return RelationSchema::of([new readonly class($types) implements RelationDeclarations {
            /**
             * @param list<ResourceType> $types
             */
            public function __construct(
                private array $types,
            ) {
            }

            public function types(): iterable
            {
                return $this->types;
            }
        }]);
    }

    /**
     * What the schema is built with, as the mutant on has it: what was built with another needs
     * building again (in Symfony, PolicyKernel does it).
     */
    public function schemaKey(): ?string
    {
        return MutationOperator::DropImplication === $this->active?->operator ? $this->active->id() : null;
    }

    /**
     * @param iterable<PolicyElement> $elements
     *
     * @return list<PolicyElement>
     */
    public function elements(iterable $elements): array
    {
        $this->wired[] = 'elements';
        $wrapped = [];

        foreach ($elements as $element) {
            // YorvoID's own: the grant operators break what they decide by.
            if (!$element instanceof RolePermit && !$element instanceof RelationPermit && !$element instanceof NoEscalation) {
                $this->elements[$element->name()] = match (true) {
                    $element instanceof ForbidElement => 'forbid',
                    RuleElements::isPermitSide($element) => 'rule-permit',
                    default => 'permit',
                };
            }

            $wrapped[] = match (true) {
                $element instanceof ForbidElement => new MutatedForbid($element, $this),
                $element instanceof PermitElement => new MutatedPermit($element, $this),
                default => $element,
            };
        }

        return $wrapped;
    }

    public function authority(AuthorityCheck $authority): AuthorityCheck
    {
        $this->wired[] = 'authority';

        return new MutatedAuthority($authority, $this);
    }

    public function policyStore(PolicyStore $store): PolicyStore
    {
        $this->wired[] = 'policy store';

        return new MutatedPolicyStore($store, $this);
    }

    public function relationStore(RelationStore $store): RelationStore
    {
        $this->wired[] = 'relation store';

        return new MutatedRelationStore($store, $this);
    }

    /**
     * Turns one on, or none.
     */
    public function activate(?Mutant $mutant): void
    {
        $this->active = $mutant;
    }

    public function active(): ?Mutant
    {
        return $this->active;
    }

    /**
     * Whether the mutant on is this one.
     */
    public function is(MutationOperator $operator, ?string $subject = null): bool
    {
        return $this->active?->operator === $operator && $this->active->subject === $subject;
    }

    /**
     * What an element of the application's gave, as the mutant on changes it; and, with none on, what
     * the cases showed.
     */
    public function truth(PolicyElement $element, Truth $truth): Truth
    {
        $name = $element->name();

        if (null === $this->active) {
            match ($truth->value) {
                TruthValue::Holds => $this->held[] = $name,
                TruthValue::Unknown => $this->unknown[] = $name,
                TruthValue::Fails => null,
            };
        }

        return match (true) {
            $this->is(MutationOperator::DropForbid, $name), $this->is(MutationOperator::PermitNever, $name) => Truth::fails('Mutant: it never holds.'),
            $this->is(MutationOperator::ForbidEverything, $name), $this->is(MutationOperator::PermitAlways, $name) => Truth::holds('Mutant: it holds whenever it matches.'),
            $this->is(MutationOperator::CanTell, $name) && TruthValue::Unknown === $truth->value => Truth::fails('Mutant: it fails where it couldn\'t tell.'),
            default => $truth,
        };
    }

    /**
     * The grants the mutant on changes: each ability, the role, whether it's granted, and the roles the
     * ability has by default.
     *
     * @return list<array{string, string, bool, list<string>}>
     */
    public function grants(): array
    {
        $active = $this->active;
        $grants = [];

        foreach ($this->catalogue?->abilities() ?? [] as $ability) {
            $role = $active?->role;
            $granted = match ($active?->operator) {
                MutationOperator::Widen, MutationOperator::StrictOff => $ability->name === $active->subject ? true : null,
                MutationOperator::Narrow => $ability->name === $active->subject ? false : null,
                MutationOperator::Super => $ability->strict || 'yorvoid' === $ability->owner ? null : true,
                default => null,
            };

            if (null !== $granted && null !== $role) {
                $grants[] = [$ability->name, $role, $granted, $ability->defaultRoles];
            }
        }

        return $grants;
    }

    /**
     * Whether a tuple with this relation leads to a parent, as the schema declares, or as roles on a
     * parent are followed ("parent").
     */
    public function leadsToParent(string $relation): bool
    {
        return \in_array($relation, $this->parentRelations(), true);
    }

    /**
     * Every mutant, in a stable order: the grants ability by ability, then the roles, the structure,
     * the elements and the authority check.
     *
     * @return list<Mutant>
     *
     * @throws IntegratorError When a seam isn't wired, so mutants of it would change nothing
     */
    public function all(): array
    {
        foreach (['catalogue', 'elements', 'authority', 'policy store'] as $seam) {
            if (!\in_array($seam, $this->wired, true)) {
                throw new IntegratorError(\sprintf('The policy under test wasn\'t built through the mutants\' %s.', $seam), 'Pass the catalogue, the elements, the authority check and the stores through Mutants when the policy is built.', 'how-to/test-your-policy.md#mutation-testing');
            }
        }

        $catalogue = $this->catalogue;
        \assert(null !== $catalogue);
        // Name => whether it's a super role.
        $roles = [Role::EVERYONE => false, ...array_map(static fn (Role $role): bool => true === $role->super, $catalogue->roles())];
        $abilities = array_filter($catalogue->abilities(), static fn (Ability $ability): bool => 'yorvoid' !== $ability->owner);
        $mutants = [];

        foreach ($abilities as $ability) {
            foreach ($roles as $role => $super) {
                $mutant = self::grant($ability, $role, $super);

                if (null !== $mutant) {
                    $mutants[] = $mutant;
                }
            }
        }

        foreach ($roles as $role => $super) {
            // A role that has every ability that isn't strict is as good as super already.
            $lacks = array_filter($abilities, static fn (Ability $ability): bool => !$ability->strict && [] === array_intersect([$role, Role::EVERYONE], $ability->defaultRoles));

            if (!$super && Role::EVERYONE !== $role && [] !== $lacks) {
                $mutants[] = new Mutant(MutationOperator::Super, role: $role);
            }
        }

        $mutants[] = new Mutant(MutationOperator::ScopeWidens);
        $relations = \in_array('relation store', $this->wired, true);

        if ($relations && null !== $this->schema?->type(GroupRelations::TYPE)) {
            $mutants[] = new Mutant(MutationOperator::GroupsDontCount);
        }

        if ($relations && [] !== $this->parentRelations()) {
            $mutants[] = new Mutant(MutationOperator::ParentsDontCount);
        }

        foreach ($relations ? $this->schema?->types() ?? [] : [] as $type) {
            foreach ($type->relations as $definition) {
                foreach ($definition->impliedBy as $implied) {
                    $mutants[] = new Mutant(MutationOperator::DropImplication, $type->name.'#'.$definition->name, $implied);
                }
            }
        }

        $elements = $this->elements;
        ksort($elements, \SORT_STRING);

        foreach ($elements as $name => $kind) {
            array_push($mutants, ...self::ofElement($name, $kind));
        }

        $mutants[] = new Mutant(MutationOperator::AuthorityOff);

        return $mutants;
    }

    /**
     * What a role's grant of an ability can be changed into, if anything.
     */
    private static function grant(Ability $ability, string $role, bool $super): ?Mutant
    {
        $has = \in_array($role, $ability->defaultRoles, true);

        return match (true) {
            // A super role has what isn't strict whatever its grants.
            $super && !$ability->strict => null,
            $has => new Mutant(MutationOperator::Narrow, $ability->name, $role),
            $super => new Mutant(MutationOperator::StrictOff, $ability->name, $role),
            // Everyone's: every role has it.
            \in_array(Role::EVERYONE, $ability->defaultRoles, true) => null,
            default => new Mutant(MutationOperator::Widen, $ability->name, $role),
        };
    }

    /**
     * @param 'permit'|'forbid'|'rule-permit' $kind
     *
     * @return list<Mutant>
     */
    private static function ofElement(string $name, string $kind): array
    {
        return match (true) {
            'forbid' === $kind => [new Mutant(MutationOperator::DropForbid, $name), new Mutant(MutationOperator::ForbidEverything, $name), new Mutant(MutationOperator::CanTell, $name)],
            // A rule's permit side is never unknown: what the rule can't tell, its forbid side says.
            'rule-permit' === $kind => [new Mutant(MutationOperator::PermitAlways, $name), new Mutant(MutationOperator::PermitNever, $name)],
            default => [new Mutant(MutationOperator::PermitAlways, $name), new Mutant(MutationOperator::PermitNever, $name), new Mutant(MutationOperator::CanTell, $name)],
        };
    }

    /**
     * Whether a case reached what the mutant changes: a forbid that held, an element that couldn't
     * tell. One that wasn't can't be caught by the cases as they are.
     */
    public function exercised(Mutant $mutant): bool
    {
        return match ($mutant->operator) {
            MutationOperator::DropForbid => \in_array($mutant->subject, $this->held, true),
            MutationOperator::CanTell => \in_array($mutant->subject, $this->unknown, true),
            default => true,
        };
    }

    /**
     * The relations that lead to a parent: those the schema's relations are inherited through, and
     * "parent" where a type declares it, which roles on a parent are followed by.
     *
     * @return list<string>
     */
    private function parentRelations(): array
    {
        $relations = [];

        foreach ($this->schema?->types() ?? [] as $type) {
            foreach ($type->relations as $definition) {
                $relations = [...$relations, ...array_keys($definition->fromParents), ...('parent' === $definition->name ? ['parent'] : [])];
            }
        }

        return $relations;
    }
}
