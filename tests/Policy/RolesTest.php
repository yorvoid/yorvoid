<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\Role;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Roles;
use YorvoID\Policy\RoleSource;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Policy\UnknownAbility;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Policy\Fixtures\AcmeDeclarations;
use YorvoID\Tests\Relations\Fixtures\AcmeRelations;

/**
 * The permit by roles: defaults with the realm's changes, super roles and strict abilities, the
 * "everyone" pseudo-role, roles on one resource, expiry, and roles from a source.
 */
final class RolesTest extends TestCase
{
    private InMemoryPolicyStore $store;

    private MockClock $clock;

    private Realm $realm;

    /** @var list<string> */
    private array $sourced = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryPolicyStore();
        $this->clock = new MockClock('2026-11-07 20:00:00');
        $this->realm = new Realm('acme');
    }

    public function testEveryoneHasWhatTheEveryoneRoleHas(): void
    {
        $decision = $this->decide(new Anonymous(), 'acme.pages.view');

        self::assertSame(Verdict::Permit, $decision->verdict);
        self::assertSame('Everyone has "acme.pages.view".', $decision->reason);
        self::assertSame(Verdict::Deny, $this->decide(new Anonymous(), 'acme.pages.publish')->verdict);
        self::assertSame('None of their roles has "acme.pages.publish".', $this->decide(new Anonymous(), 'acme.pages.publish')->reason);
    }

    public function testARoleHasItsDefaultsWithTheRealmsChanges(): void
    {
        $ada = $this->principal('editor');

        self::assertSame('Their role "editor" has "acme.pages.publish".', $this->decide($ada, 'acme.pages.publish')->reason);
        self::assertSame('Everyone has "acme.pages.view".', $this->decide($ada, 'acme.pages.view')->reason, 'A member has what everyone has.');

        $this->store->saveChanges($this->realm, new AbilityChanges(['acme.pages.purge' => ['editor']], ['acme.pages.publish' => ['editor']]));

        self::assertFalse($this->decide($ada, 'acme.pages.publish')->permits(), 'Taken from the role.');
        self::assertTrue($this->decide($ada, 'acme.pages.purge')->permits(), 'Given to the role.');
        self::assertFalse($this->decide($ada, 'acme.pages.publish', realm: new Realm('other'))->permits(), 'Another realm\'s changes, and no role there.');
    }

    public function testASuperRoleHasEveryAbilityThatIsntStrict(): void
    {
        $admin = $this->principal('admin');

        $decision = $this->decide($admin, 'acme.pages.publish');
        self::assertSame(Verdict::Permit, $decision->verdict);
        self::assertSame('Their role "admin" is a super role: it has every ability that isn\'t strict.', $decision->reason);
        self::assertTrue($this->decide($admin, 'acme.sites.update')->permits());

        $strict = $this->decide($admin, 'acme.pages.purge');
        self::assertSame(Verdict::Deny, $strict->verdict);
        self::assertSame('None of their roles has "acme.pages.purge": it\'s strict, so a super role has it only when it\'s granted to it.', $strict->reason);

        $this->store->saveChanges($this->realm, new AbilityChanges(['acme.pages.purge' => ['admin']]));
        self::assertSame('Their role "admin" has "acme.pages.purge".', $this->decide($admin, 'acme.pages.purge')->reason);

        $this->store->saveChanges($this->realm, new AbilityChanges([], ['acme.pages.publish' => ['admin']]));
        self::assertTrue($this->decide($admin, 'acme.pages.publish')->permits(), 'A super role keeps what isn\'t strict: taking it from a role it isn\'t a default of changes nothing.');
    }

    public function testARoleOnAResourceCountsForThatResourceOnly(): void
    {
        $ada = $this->principal();
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'manager', $this->clock->now(), new ResourceRef('site', 'a')));

        self::assertSame('Their role "manager" has "acme.sites.update".', $this->decide($ada, 'acme.sites.update', new ResourceRef('site', 'a'))->reason);
        self::assertFalse($this->decide($ada, 'acme.sites.update', new ResourceRef('site', 'b'))->permits());
        self::assertFalse($this->decide($ada, 'acme.sites.update')->permits(), 'Not in the whole realm.');
    }

    public function testAnExpiredRoleCountsForNothing(): void
    {
        $ada = $this->principal();
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'editor', $this->clock->now(), expiresAt: $this->clock->now()->modify('+1 hour')));

        self::assertTrue($this->decide($ada, 'acme.pages.publish')->permits());
        $this->clock->sleep(3600);
        self::assertFalse($this->decide($ada, 'acme.pages.publish')->permits(), 'It stops counting at its expiry.');
    }

    public function testRolesOfAnotherRealmAndUnknownRolesCountForNothing(): void
    {
        $ada = $this->principal();
        $this->store->assign(new RoleAssignment(new Realm('other'), self::id($ada), 'editor', $this->clock->now()));
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'ghost', $this->clock->now()));

        self::assertFalse($this->decide($ada, 'acme.pages.publish')->permits());
        $elsewhere = new Member(new Realm('other'), self::id($ada));
        self::assertSame('None of their roles has "acme.pages.publish".', $this->decide($elsewhere, 'acme.pages.publish')->reason, 'An actor of another realm has only what everyone has here.');
    }

    public function testASourceAddsRolesForTheRequestOnly(): void
    {
        $ada = $this->principal();
        $this->sourced = ['editor', 'not-a-role'];

        self::assertTrue($this->decide($ada, 'acme.pages.publish')->permits());
        self::assertTrue($this->decide(new Anonymous(), 'acme.pages.publish')->permits(), 'A source speaks for anyone it names roles for.');

        $this->sourced = [];
        self::assertFalse($this->decide($ada, 'acme.pages.publish')->permits());
        self::assertSame([], $this->store->assignments($this->realm, self::id($ada)), 'Nothing is stored.');
    }

    public function testEachRoleCountsOnce(): void
    {
        $ada = $this->principal('editor');
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'editor', $this->clock->now(), new ResourceRef('page', '1')));
        $this->sourced = ['editor', 'member'];
        $request = Request::forAbility('acme.pages.publish', $this->realm, new ResourceRef('page', '1'), $ada, new Origin(OriginKind::Web), $this->clock->now());

        self::assertSame(['everyone', 'editor', 'member'], $this->roles()->rolesOf($request));
    }

    public function testAStrictAbilityIsSaidToBeOneOnlyToASuperRole(): void
    {
        self::assertSame('None of their roles has "acme.pages.purge".', $this->decide($this->principal('editor'), 'acme.pages.purge')->reason);
    }

    public function testAnUndeclaredAbilityIsNobodys(): void
    {
        $decision = $this->decide($this->principal('admin'), 'acme.pages.edit');

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame('There\'s no ability "acme.pages.edit".', $decision->reason);
    }

    public function testReadsTheRealmsRolesAndAbilities(): void
    {
        $roles = $this->roles();
        $this->store->saveRole($this->realm, 'reviewer', 'Reviewer');
        $this->store->saveRole($this->realm, 'editor', 'Shadowed');
        $this->store->saveChanges($this->realm, new AbilityChanges(['acme.pages.view_drafts' => ['reviewer']], ['acme.pages.delete' => ['editor']]));

        self::assertSame(['everyone' => 'Everyone', 'member' => 'Member', 'editor' => 'Editor', 'manager' => 'Site manager', 'admin' => 'Admin', 'reviewer' => 'Reviewer'], $roles->roles($this->realm), 'A declared role wins over one created with its name.');
        self::assertTrue($roles->has($this->realm, 'reviewer'));
        self::assertTrue($roles->has($this->realm, 'everyone'));
        self::assertFalse($roles->has($this->realm, 'ghost'));
        self::assertFalse($roles->has(new Realm('other'), 'reviewer'));
        $reviewer = $roles->role($this->realm, 'reviewer');
        self::assertNotNull($reviewer);
        self::assertSame('Reviewer', $reviewer->label);
        self::assertFalse($reviewer->declared());
        self::assertSame('acme', $roles->role($this->realm, 'editor')?->owner);
        self::assertNull($roles->role($this->realm, 'everyone'));
        self::assertNull($roles->role($this->realm, 'ghost'));
        self::assertSame(['editor', 'reviewer'], $roles->rolesWith($this->realm, 'acme.pages.view_drafts'));
        self::assertSame(['editor'], $roles->defaultRolesWith('acme.pages.delete'));
        self::assertSame([], $roles->rolesWith($this->realm, 'acme.pages.delete'));
        self::assertSame(['acme.pages.view_drafts', 'acme.pages.publish'], $roles->abilitiesOf($this->realm, 'editor'));
        self::assertSame([], $roles->abilitiesOf($this->realm, 'admin'), 'A super role\'s abilities for being one aren\'t listed.');
        self::assertSame(['acme.pages.view'], $roles->abilitiesOf($this->realm, 'everyone'));
        self::assertTrue($roles->changes($this->realm)->equals($this->store->changes($this->realm)));
        self::assertTrue($roles->allows($this->realm, [Role::EVERYONE, 'reviewer'], 'acme.pages.view_drafts'));
        self::assertTrue($roles->allows($this->realm, ['admin'], 'acme.pages.delete'));
        self::assertFalse($roles->allows($this->realm, ['admin'], 'acme.pages.purge'));
        self::assertFalse($roles->allows($this->realm, ['admin'], 'acme.ghost'));

        try {
            $roles->rolesWith($this->realm, 'acme.ghost');
            self::fail('An undeclared ability had roles.');
        } catch (UnknownAbility $error) {
            self::assertSame('acme.ghost', $error->ability);
            self::assertSame('There is no ability "acme.ghost".', $error->getMessage());
        }
    }

    public function testReadsTheActorsRolesOncePerDecision(): void
    {
        /** @var \ArrayObject<int, Request> $counting */
        $counting = new \ArrayObject();
        $ada = $this->principal('editor');
        $roles = new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $this->store, [new readonly class($counting) implements RoleSource {
            /**
             * @param \ArrayObject<int, Request> $asked
             */
            public function __construct(
                private \ArrayObject $asked,
            ) {
            }

            public function roles(Request $request): iterable
            {
                $this->asked->append($request);

                return [];
            }
        }]);
        $request = Request::forAbility('acme.pages.publish', $this->realm, null, $ada, new Origin(OriginKind::Web), $this->clock->now());

        self::assertSame(['everyone', 'editor'], $roles->rolesOf($request));
        self::assertSame(['everyone', 'editor'], $roles->rolesOf($request));
        self::assertSame(TruthValue::Holds, $roles->decide($request)->value);
        self::assertCount(1, $counting, 'One reading for the request.');
        self::assertSame([], $roles->missing($request, ['acme.pages.publish', 'acme.pages.view']));
        self::assertSame(['acme.pages.purge'], $roles->missing($request, ['acme.pages.purge', 'acme.pages.delete']));
        self::assertSame(['acme.pages.purge'], $roles->missing($request, ['acme.pages.view', 'acme.pages.purge']), 'A list, in their order.');
        self::assertSame(['everyone', 'editor'], $roles->rolesOf(Request::forAbility('acme.pages.publish', $this->realm, null, $ada, new Origin(OriginKind::Web), $this->clock->now())));
        self::assertCount(2, $counting, 'Another request reads again.');
    }

    public function testTheRolePermitIsAboutRequestsForAnAbility(): void
    {
        $permit = new RolePermit($this->roles());
        $origin = new Origin(OriginKind::Web);

        self::assertSame('yorvoid.roles', $permit->name());
        self::assertTrue($permit->matches(Request::forAbility('acme.pages.view', $this->realm, null, new Anonymous(), $origin, $this->clock->now())));
        $withoutAbility = Request::forOperation(new OperationDescription('acme.x', 'X', [AuthorizedBy::Operator], null, null, IssueBadge::class, []), new IssueBadge($this->realm, new PrincipalId(new UuidV7())), new Anonymous(), $origin, $this->clock->now());
        self::assertFalse($permit->matches($withoutAbility));
        self::assertSame([TruthValue::Fails, 'No ability is asked for.'], [$permit->evaluate($withoutAbility)->value, $permit->evaluate($withoutAbility)->detail]);
    }

    private function principal(?string $role = null): Member
    {
        $member = new Member($this->realm, new PrincipalId(new UuidV7()));

        if (null !== $role) {
            $this->store->assign(new RoleAssignment($this->realm, self::id($member), $role, $this->clock->now()));
        }

        return $member;
    }

    private static function id(Member $member): PrincipalId
    {
        \assert(null !== $member->principalId);

        return $member->principalId;
    }

    public function testABatchsRolesAreThoseOfEachRequestAlone(): void
    {
        $ada = new Member($this->realm, new PrincipalId(new UuidV7()));
        $at = $this->clock->now();
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'member', $at));
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'editor', $at, new ResourceRef('page', '1')));
        $this->store->assign(new RoleAssignment($this->realm, self::id($ada), 'manager', $at, new ResourceRef('site', 'a')));
        $relationStore = new InMemoryRelationStore();
        $relationStore->add(new RelationTuple($this->realm, new ResourceRef('page', '2'), 'parent', new Subject(new ResourceRef('site', 'a'))));
        $relations = new Relations(RelationSchema::of([new AcmeRelations(), new GroupRelations()]), $relationStore);
        $request = fn (Actor $actor, ?ResourceRef $resource, ?Realm $realm = null): Request => Request::forAbility('acme.pages.publish', $realm ?? $this->realm, $resource, $actor, new Origin(OriginKind::Web), $at);

        foreach ([null, $relations] as $with) {
            $requests = [
                $request($ada, new ResourceRef('page', '1')),
                $request($ada, new ResourceRef('page', '2')),
                $request($ada, null),
                $request(new Anonymous(), new ResourceRef('page', '1')),
                $request($ada, new ResourceRef('page', '1'), new Realm('other')),
            ];
            $batched = new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $this->store, relations: $with);
            $memoised = $batched->rolesOf($requests[2]);
            $batched->preload($requests);

            foreach ($requests as $i => $one) {
                $alone = new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $this->store, relations: $with)->rolesOf($one);
                self::assertSame($alone, $batched->rolesOf($one), \sprintf('Request %d, %s relations', $i, null === $with ? 'without' : 'with'));
            }

            self::assertSame($memoised, $batched->rolesOf($requests[2]));
        }

        self::assertSame(['everyone', 'editor', 'member'], new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $this->store, relations: $relations)->rolesOf($request($ada, new ResourceRef('page', '1'))), 'Its own resource\'s roles count.');
        self::assertSame(['everyone', 'manager', 'member'], new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $this->store, relations: $relations)->rolesOf($request($ada, new ResourceRef('page', '2'))), 'And those of what it\'s within.');
    }

    private function roles(): Roles
    {
        return new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $this->store, [new readonly class(fn (): array => $this->sourced) implements RoleSource {
            /**
             * @param \Closure(): list<string> $roles
             */
            public function __construct(
                private \Closure $roles,
            ) {
            }

            public function roles(Request $request): iterable
            {
                return ($this->roles)();
            }
        }]);
    }

    private function decide(Actor $actor, string $ability, ?ResourceRef $resource = null, ?Realm $realm = null): Decision
    {
        $evaluator = new Evaluator([new RolePermit($this->roles())], new StubAuthority(Truth::holds('valid')), $this->clock);

        return $evaluator->decideAbility($actor, $ability, $realm ?? $this->realm, $resource, new Origin(OriginKind::Web));
    }
}
