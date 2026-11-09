<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\Attribute\AttributeChanges;
use YorvoID\Policy\Attribute\AttributeResolver;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\GroupChanges;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyChanges;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\PolicyVersion;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Roles;
use YorvoID\Policy\RoleSource;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleElements;
use YorvoID\Policy\Storage\CachedPolicyStore;
use YorvoID\Policy\ViewAs\Views;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\RelationChanges;
use YorvoID\Relations\RelationPermit;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationsVersion;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Relations\Fixtures\AcmeRelations;
use YorvoID\Tests\Support\Stack;

/**
 * YorvoID with the policy, in memory or on a stack's stores, cached if given a cache: operations run as
 * they do in an application, the host's operator from the console, principals as themselves.
 */
final class PolicyWorld
{
    public readonly InMemoryStorage $memory;

    public readonly MockClock $clock;

    public readonly Realm $realm;

    public readonly Roles $roles;

    public readonly Relations $relations;

    public readonly Attributes $attributes;

    public readonly Operations $operations;

    public readonly OperationCatalogue $catalogue;

    public readonly Policy $policy;

    public readonly PolicyStore $policyStore;

    public readonly RelationStore $relationStore;

    /**
     * @param list<PolicyDeclarations>                $declarations
     * @param list<Rule>                              $rules
     * @param list<RoleSource>                        $sources
     * @param list<AttributeResolver>                 $resolvers
     * @param (\Closure(Attributes): list<Rule>)|null $rulesOf      Rules that read attributes
     * @param Stack|null                              $stack        Its stores instead of in-memory ones
     * @param InMemoryStorage|null                    $memory       The in-memory stores, if given
     */
    public function __construct(array $declarations = [], array $rules = [], array $sources = [], ?StepUpCheck $stepUp = null, ?Duration $window = null, int $maxDepth = Relations::MAX_DEPTH, array $resolvers = [], ?\Closure $rulesOf = null, ?Stack $stack = null, private readonly ?VersionedCache $cache = null, ?InMemoryStorage $memory = null)
    {
        $this->memory = $memory ?? new InMemoryStorage();
        $principals = $stack->principals ?? $this->memory->principals;
        $identifiers = $stack->identifiers ?? $this->memory->identifiers;
        $transactions = $stack->transactions ?? $this->memory->transactions;
        $attributes = $stack->attributes ?? $this->memory->attributes;
        $policyStore = $stack->policy ?? $this->memory->policy;
        $relationStore = $stack->relations ?? $this->memory->relations;
        $this->policyStore = null === $cache ? $policyStore : new CachedPolicyStore($policyStore, $cache);
        $this->relationStore = null === $cache ? $relationStore : new CachedRelationStore($relationStore, $cache);
        $this->clock = new MockClock('2026-11-07 20:00:00');
        $this->realm = new Realm('acme');
        $catalogue = PolicyCatalogue::of([new YorvoIDDeclarations(), new AcmeDeclarations(), ...$declarations]);
        $this->relations = new Relations(RelationSchema::of([new AcmeRelations(), new GroupRelations()]), $this->relationStore, $maxDepth);
        $this->roles = new Roles($catalogue, $this->policyStore, $sources, $this->relations);
        $this->attributes = new Attributes([new AcmeAttributes()], $attributes, $resolvers);
        $log = new SecurityLog($stack->log ?? $this->memory->log, $transactions, clock: $this->clock);
        $elements = [new RolePermit($this->roles, $stepUp, $window), new NoEscalation($this->roles), new RelationPermit($this->relations)];

        foreach ([...$rules, ...(null === $rulesOf ? [] : $rulesOf($this->attributes))] as $rule) {
            array_push($elements, ...RuleElements::of($rule));
        }

        $evaluator = Evaluator::builtIn(new PrincipalAuthority($principals), elements: $elements, commandLine: true, clock: $this->clock, versions: [new PolicyVersion($this->policyStore), new RelationsVersion($this->relationStore)]);
        $handlers = [
            new Principals($principals, $identifiers, $log, $transactions, clock: $this->clock),
            new PolicyChanges($catalogue, $this->policyStore, $principals, $log, $transactions, $this->clock),
            new GroupChanges($catalogue, $this->policyStore, $this->relationStore, $principals, $log, $transactions, $this->clock),
            new RelationChanges($this->relations->schema(), $this->relationStore, $log, $transactions),
            new AttributeChanges($this->attributes, $attributes, $principals, $log, $transactions),
            new Views($this->roles, $principals, $log, $this->clock),
        ];
        $this->catalogue = OperationCatalogue::of($handlers);
        $this->operations = new Operations($this->catalogue, $evaluator, $log, $handlers);
        $this->policy = new Policy($evaluator, $this->roles);
    }

    /**
     * What the container's reset does between requests: the cache and the cached stores forget.
     */
    public function newRequest(): void
    {
        $this->cache?->reset();

        foreach ([$this->policyStore, $this->relationStore] as $store) {
            if ($store instanceof CachedPolicyStore || $store instanceof CachedRelationStore) {
                $store->reset();
            }
        }
    }

    /**
     * @template T
     *
     * @param Command<T> $command
     *
     * @return T
     */
    public function asOperator(Command $command): mixed
    {
        return $this->operations->run($command, new Operator(), new Origin(OriginKind::Console));
    }

    /**
     * @template T
     *
     * @param Command<T> $command
     *
     * @return T
     */
    public function as(Actor $actor, Command $command): mixed
    {
        return $this->operations->run($command, $actor, new Origin(OriginKind::Web));
    }

    /**
     * A new, active principal of the realm, with these roles in the whole realm.
     */
    public function principal(string ...$roles): ActingPrincipal
    {
        $principal = $this->asOperator(new CreatePrincipal($this->realm, PrincipalKind::Human));

        foreach ($roles as $role) {
            $this->asOperator(new AssignRole($this->realm, $principal->id, $role));
        }

        return new ActingPrincipal($this->realm, $principal->id);
    }

    public function decide(Actor $actor, string $ability, ?ResourceRef $resource = null): Decision
    {
        return $this->policy->decide($actor, $ability, $this->realm, $resource, new Origin(OriginKind::Web));
    }

    public function allows(Actor $actor, string $ability, ?ResourceRef $resource = null): bool
    {
        return $this->decide($actor, $ability, $resource)->permits();
    }
}
