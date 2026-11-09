<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\Ability;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\PolicyTesting\Mutation\Mutant;
use YorvoID\PolicyTesting\Mutation\Mutants;
use YorvoID\PolicyTesting\Mutation\MutatedForbid;
use YorvoID\PolicyTesting\Mutation\MutatedPermit;
use YorvoID\PolicyTesting\Mutation\MutationOperator;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\ResourceType;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;

/**
 * What each mutant changes, at the seam it changes it, and which mutants a policy gets.
 */
final class MutantsTest extends TestCase
{
    public function testChangesTheGrants(): void
    {
        $mutants = self::mutants();

        self::assertSame([], $mutants->grants(), 'None on.');
        $mutants->activate(new Mutant(MutationOperator::Widen, 'acme.write', 'reader'));
        self::assertSame([['acme.write', 'reader', true, ['writer', 'both']]], $mutants->grants());
        $mutants->activate(new Mutant(MutationOperator::Narrow, 'acme.write', 'writer'));
        self::assertSame([['acme.write', 'writer', false, ['writer', 'both']]], $mutants->grants());
        $mutants->activate(new Mutant(MutationOperator::StrictOff, 'acme.purge', 'boss'));
        self::assertSame([['acme.purge', 'boss', true, []]], $mutants->grants());
        $mutants->activate(new Mutant(MutationOperator::Super, role: 'reader'));
        self::assertSame([['acme.read', 'reader', true, ['reader', 'both']], ['acme.write', 'reader', true, ['writer', 'both']], ['acme.open', 'reader', true, ['everyone']]], $mutants->grants(), 'What isn\'t strict, and not YorvoID\'s.');
        $mutants->activate(new Mutant(MutationOperator::ScopeWidens));
        self::assertSame([], $mutants->grants());

        $store = $mutants->policyStore(new InMemoryPolicyStore());
        $mutants->activate(new Mutant(MutationOperator::Widen, 'acme.write', 'reader'));
        self::assertSame(['writer', 'reader'], $store->changes(new Realm('acme'))->rolesWith('acme.write', ['writer']));

        $empty = new Mutants();
        $empty->activate(new Mutant(MutationOperator::Super, role: 'reader'));
        self::assertSame([], $empty->grants(), 'Without a catalogue, nothing to grant.');
    }

    public function testChangesWhatTheStoresRead(): void
    {
        $realm = new Realm('acme');
        $mutants = self::mutants();
        $inner = new InMemoryPolicyStore();
        $store = $mutants->policyStore($inner);
        $ada = new PrincipalId(new UuidV7());
        $page = new ResourceRef('page', '1');
        $at = new \DateTimeImmutable('2026-11-09 12:00:00');
        $inner->assign(new RoleAssignment($realm, $ada, 'writer', $at, $page));
        $inner->assign(new RoleAssignment($realm, $ada, 'reader', $at));
        $inner->assignGroup(new GroupRoleAssignment($realm, 'team', 'writer', $at, $page));
        $inner->assignGroup(new GroupRoleAssignment($realm, 'team', 'reader', $at));
        $scopes = self::scopes(...);

        self::assertSame([null, 'page:1'], $scopes($store->assignments($realm, $ada)));
        self::assertSame([null, 'page:1'], $scopes($store->groupAssignments($realm, ['team'])));
        $mutants->activate(new Mutant(MutationOperator::ScopeWidens));
        self::assertSame([null, null], $scopes($store->assignments($realm, $ada)));
        self::assertSame([null, null], $scopes($store->groupAssignments($realm, ['team'])));
        self::assertSame(['reader', 'writer'], array_map(static fn (GroupRoleAssignment $one): string => $one->role, $store->groupAssignments($realm, ['team'])));
        $mutants->activate(new Mutant(MutationOperator::GroupsDontCount));
        self::assertSame([], $store->groupAssignments($realm, ['team']));
        self::assertCount(2, $store->assignments($realm, $ada), 'Its own roles count.');

        $relations = new InMemoryRelationStore();
        $relationStore = $mutants->relationStore($relations);
        $site = new ResourceRef('site', '1');
        $tuples = [
            new RelationTuple($realm, $page, 'within', new Subject($site)),
            new RelationTuple($realm, $page, 'editor', new Subject(GroupRelations::group('team'), GroupRelations::MEMBER)),
            new RelationTuple($realm, GroupRelations::group('team'), GroupRelations::MEMBER, Subject::principal($ada)),
            new RelationTuple($realm, $page, 'editor', Subject::principal($ada)),
        ];

        foreach ($tuples as $tuple) {
            $relations->add($tuple);
        }

        $read = static fn (): array => array_map(static fn (RelationTuple $tuple): string => (string) $tuple, [...$relationStore->of($realm, $page), ...$relationStore->objects($realm, [Subject::principal($ada)], GroupRelations::MEMBER), ...$relationStore->subjects($realm, [[$page, 'within']])]);
        $mutants->activate(null);
        self::assertCount(5, $read());
        $mutants->activate(new Mutant(MutationOperator::GroupsDontCount));
        self::assertSame(['page:1#editor@principal:'.$ada->uuid->toRfc4122(), 'page:1#within@site:1', 'page:1#within@site:1'], $read());
        $mutants->activate(new Mutant(MutationOperator::ParentsDontCount));
        self::assertSame(['page:1#editor@group:team#member', 'page:1#editor@principal:'.$ada->uuid->toRfc4122(), 'group:team#member@principal:'.$ada->uuid->toRfc4122()], $read(), '"within" leads to a parent, as the schema says.');
        self::assertTrue($mutants->leadsToParent('within'));
        self::assertTrue($mutants->leadsToParent('parent'), 'The folder\'s.');
        self::assertFalse($mutants->leadsToParent('editor'));
    }

    public function testChangesTheElementsAndTheAuthority(): void
    {
        $mutants = self::mutants();
        $request = Request::forAbility('acme.read', new Realm('acme'), null, new \YorvoID\Core\Operation\Anonymous(), new Origin(OriginKind::Web), new \DateTimeImmutable());
        [$forbid, $permit, $unsure] = $mutants->elements([self::forbid('acme.locked', Truth::fails('Open.')), self::permit('acme.open', Truth::holds('Open.')), self::permit('acme.unsure', Truth::unknown(IndeterminateReason::MissingData, 'Unsure.'))]);
        $value = static fn (PermitElement|ForbidElement $element): TruthValue => $element->evaluate($request)->value;

        self::assertInstanceOf(MutatedForbid::class, $forbid);
        self::assertInstanceOf(MutatedPermit::class, $permit);
        \assert($unsure instanceof MutatedPermit);
        self::assertSame([TruthValue::Fails, TruthValue::Holds, TruthValue::Unknown], [$value($forbid), $value($permit), $value($unsure)], 'Nothing on.');
        self::assertSame(AuthorizedBy::Ability, $permit->basis());
        self::assertTrue($permit->matches($request));
        self::assertSame('acme.locked', $forbid->name());

        foreach ([
            [new Mutant(MutationOperator::ForbidEverything, 'acme.locked'), $forbid, TruthValue::Holds],
            [new Mutant(MutationOperator::DropForbid, 'acme.locked'), $forbid, TruthValue::Fails],
            [new Mutant(MutationOperator::PermitNever, 'acme.open'), $permit, TruthValue::Fails],
            [new Mutant(MutationOperator::PermitAlways, 'acme.unsure'), $unsure, TruthValue::Holds],
            [new Mutant(MutationOperator::CanTell, 'acme.unsure'), $unsure, TruthValue::Fails],
            [new Mutant(MutationOperator::CanTell, 'acme.open'), $permit, TruthValue::Holds],
            [new Mutant(MutationOperator::PermitNever, 'acme.other'), $permit, TruthValue::Holds],
        ] as [$mutant, $element, $expected]) {
            $mutants->activate($mutant);
            self::assertSame($expected, $value($element), $mutant->id());
        }

        $authority = $mutants->authority(new StubAuthority(Truth::fails('Suspended.')));
        $mutants->activate(null);
        self::assertSame(TruthValue::Fails, $authority->check($request)->value);
        $mutants->activate(new Mutant(MutationOperator::AuthorityOff));
        self::assertSame(TruthValue::Holds, $authority->check($request)->value);
    }

    public function testPassesPreloadingAndCatalogueChecksOn(): void
    {
        $inner = new class implements PermitElement, Preloads, ChecksCatalogue, AuthorityCheck {
            public int $preloaded = 0;

            public int $checked = 0;

            public function name(): string
            {
                return 'acme.preloading';
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Ability;
            }

            public function matches(Request $request): bool
            {
                return true;
            }

            public function evaluate(Request $request): Truth
            {
                return Truth::holds('Yes.');
            }

            public function check(Request $request): Truth
            {
                return Truth::holds('Yes.');
            }

            public function preload(array $requests): void
            {
                ++$this->preloaded;
            }

            public function checkCatalogue(OperationCatalogue $catalogue): void
            {
                ++$this->checked;
            }
        };
        $mutants = self::mutants();
        [$element] = $mutants->elements([$inner]);
        \assert($element instanceof MutatedPermit);
        $authority = $mutants->authority($inner);
        \assert($authority instanceof Preloads);
        $element->preload([]);
        $authority->preload([]);
        $element->checkCatalogue(new OperationCatalogue([]));
        [$forbid] = $mutants->elements([self::forbid('acme.plain', Truth::fails('No.'))]);
        \assert($forbid instanceof MutatedForbid);
        $forbid->preload([]);
        $forbid->checkCatalogue(new OperationCatalogue([]));
        $mutants->authority(new StubAuthority(Truth::holds('Yes.')));

        self::assertSame([2, 1], [$inner->preloaded, $inner->checked]);
    }

    public function testMakesTheMutantsThePolicyHas(): void
    {
        $mutants = self::mutants(relations: false);
        $mutants->elements([self::forbid('acme.locked', Truth::fails('Open.')), self::forbid('acme.rule:forbid', Truth::fails('No.')), self::permit('acme.rule:permit', Truth::holds('Yes.'))]);
        $mutants->authority(new StubAuthority(Truth::holds('valid')));

        self::assertSame([
            'widen:acme.read:everyone',
            'narrow:acme.read:reader',
            'widen:acme.read:writer',
            'narrow:acme.read:both',
            'widen:acme.write:everyone',
            'widen:acme.write:reader',
            'narrow:acme.write:writer',
            'narrow:acme.write:both',
            'widen:acme.purge:everyone',
            'widen:acme.purge:reader',
            'widen:acme.purge:writer',
            'strict_off:acme.purge:boss',
            'widen:acme.purge:both',
            'narrow:acme.open:everyone',
            'super:reader',
            'super:writer',
            'scope_widens',
            'drop_forbid:acme.locked',
            'forbid_everything:acme.locked',
            'can_tell:acme.locked',
            'drop_forbid:acme.rule:forbid',
            'forbid_everything:acme.rule:forbid',
            'can_tell:acme.rule:forbid',
            'permit_always:acme.rule:permit',
            'permit_never:acme.rule:permit',
            'authority_off',
        ], array_map(static fn (Mutant $mutant): string => $mutant->id(), $mutants->all()), 'No relation store: no groups or parents.');

        $wired = self::mutants();
        $wired->elements([]);
        $wired->authority(new StubAuthority(Truth::holds('valid')));
        self::assertContains('groups_dont_count', array_map(static fn (Mutant $mutant): string => $mutant->id(), $wired->all()));
        self::assertContains('parents_dont_count', array_map(static fn (Mutant $mutant): string => $mutant->id(), $wired->all()));

        foreach ([[new GroupRelations()], []] as $declarations) {
            $plain = self::mutants(schema: RelationSchema::of($declarations));
            $plain->elements([]);
            $plain->authority(new StubAuthority(Truth::holds('valid')));
            $ids = array_map(static fn (Mutant $mutant): string => $mutant->id(), $plain->all());
            self::assertNotContains('parents_dont_count', $ids, 'Nothing has a parent.');
            self::assertSame([] !== $declarations, \in_array('groups_dont_count', $ids, true));
        }

        $schemaless = new Mutants();
        $schemaless->catalogue(PolicyCatalogue::of([new YorvoIDDeclarations()]));
        $schemaless->elements([new readonly class implements \YorvoID\Core\Authorization\PolicyElement {
            public function name(): string
            {
                return 'acme.neither';
            }

            public function matches(Request $request): bool
            {
                return false;
            }

            public function evaluate(Request $request): Truth
            {
                return Truth::fails('No.');
            }
        }]);
        $schemaless->authority(new StubAuthority(Truth::holds('valid')));
        $schemaless->policyStore(new InMemoryPolicyStore());
        $schemaless->relationStore(new InMemoryRelationStore());
        self::assertSame(['scope_widens', 'permit_always:acme.neither', 'permit_never:acme.neither', 'can_tell:acme.neither', 'authority_off'], array_map(static fn (Mutant $mutant): string => $mutant->id(), $schemaless->all()), 'No schema: no groups or parents.');
        self::assertFalse($schemaless->leadsToParent('parent'));

        $parent = self::mutants(schema: RelationSchema::of([self::types(new ResourceType('page', [new RelationDefinition('parent')]))]));
        $parent->elements([]);
        $parent->authority(new StubAuthority(Truth::holds('valid')));
        self::assertContains('parents_dont_count', array_map(static fn (Mutant $mutant): string => $mutant->id(), $parent->all()), 'Roles on a parent are followed through "parent".');
    }

    /**
     * What the cases reached is told apart, never left out: a forbid seen holding, an element seen
     * unable to tell, and only while no mutant is on.
     */
    public function testTellsWhatTheCasesReached(): void
    {
        $mutants = self::mutants(relations: false);
        $request = Request::forAbility('acme.read', new Realm('acme'), null, new \YorvoID\Core\Operation\Anonymous(), new Origin(OriginKind::Web), new \DateTimeImmutable());
        [$forbid, $unsure] = $mutants->elements([self::forbid('acme.rule:forbid', Truth::holds('No.')), self::permit('acme.unsure', Truth::unknown(IndeterminateReason::MissingData, 'Unsure.'))]);
        $mutants->authority(new StubAuthority(Truth::holds('valid')));
        \assert($forbid instanceof MutatedForbid && $unsure instanceof MutatedPermit);
        $mutated = [new Mutant(MutationOperator::DropForbid, 'acme.rule:forbid'), new Mutant(MutationOperator::CanTell, 'acme.unsure'), new Mutant(MutationOperator::CanTell, 'acme.rule:forbid')];
        $exercised = static fn (): array => array_map($mutants->exercised(...), $mutated);
        $ids = array_map(static fn (Mutant $mutant): string => $mutant->id(), $mutants->all());

        self::assertContains('drop_forbid:acme.rule:forbid', $ids, 'Made whatever the cases do.');
        self::assertContains('can_tell:acme.unsure', $ids);
        self::assertSame([false, false, false], $exercised());
        self::assertTrue($mutants->exercised(new Mutant(MutationOperator::Widen, 'acme.read', 'writer')), 'The others are whenever the cases ask.');
        $mutants->activate(new Mutant(MutationOperator::PermitAlways, 'acme.unsure'));
        $forbid->evaluate($request);
        $unsure->evaluate($request);
        self::assertSame([false, false, false], $exercised(), 'Not while a mutant is on.');
        $mutants->activate(null);
        $forbid->evaluate($request);
        $unsure->evaluate($request);
        self::assertSame([true, true, false], $exercised());
    }

    /**
     * @param list<RoleAssignment>|list<GroupRoleAssignment> $assignments
     *
     * @return list<string|null>
     */
    private static function scopes(array $assignments): array
    {
        $scopes = [];

        foreach ($assignments as $one) {
            $scopes[] = null === $one->scope ? null : (string) $one->scope;
        }

        return $scopes;
    }

    private static function mutants(bool $relations = true, ?RelationSchema $schema = null): Mutants
    {
        $mutants = new Mutants();
        $mutants->catalogue(PolicyCatalogue::of([new YorvoIDDeclarations(), new readonly class implements PolicyDeclarations {
            public function abilities(): iterable
            {
                yield new Ability('acme.read', 'Read', 'acme', ['reader', 'both']);
                yield new Ability('acme.write', 'Write', 'acme', ['writer', 'both']);
                yield new Ability('acme.purge', 'Purge', 'acme', [], strict: true);
                yield new Ability('acme.open', 'Open', 'acme', ['everyone']);
            }

            public function roles(): iterable
            {
                yield new Role('reader', 'Reader', 'acme');
                yield new Role('writer', 'Writer', 'acme');
                yield new Role('boss', 'Boss', 'acme', super: true);
                // Has every ability that isn't strict: as good as super.
                yield new Role('both', 'Both', 'acme');
            }
        }]));
        $mutants->schema($schema ?? RelationSchema::of([new GroupRelations(), self::types(new ResourceType('page', [new RelationDefinition('within'), new RelationDefinition('editor'), new RelationDefinition('viewer', fromParents: ['within' => 'viewer'])]), new ResourceType('site', [new RelationDefinition('viewer')]), new ResourceType('folder', [new RelationDefinition('parent')]))]));
        $mutants->policyStore(new InMemoryPolicyStore());

        if ($relations) {
            $mutants->relationStore(new InMemoryRelationStore());
        }

        return $mutants;
    }

    private static function types(ResourceType ...$types): RelationDeclarations
    {
        return new readonly class(array_values($types)) implements RelationDeclarations {
            /**
             * @param array<ResourceType> $types
             */
            public function __construct(
                private array $types,
            ) {
            }

            public function types(): iterable
            {
                return $this->types;
            }
        };
    }

    private static function forbid(string $name, Truth $truth): ForbidElement
    {
        return new readonly class($name, $truth) implements ForbidElement {
            public function __construct(
                private string $name,
                private Truth $truth,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function matches(Request $request): bool
            {
                return true;
            }

            public function evaluate(Request $request): Truth
            {
                return $this->truth;
            }
        };
    }

    private static function permit(string $name, Truth $truth): PermitElement
    {
        return new readonly class($name, $truth) implements PermitElement {
            public function __construct(
                private string $name,
                private Truth $truth,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Ability;
            }

            public function matches(Request $request): bool
            {
                return true;
            }

            public function evaluate(Request $request): Truth
            {
                return $this->truth;
            }
        };
    }
}
