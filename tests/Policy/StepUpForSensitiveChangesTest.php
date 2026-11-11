<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Policy\Ability;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\Attribute\AttributeChanges;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RemoveGroupMember;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Policy\Effects;
use YorvoID\Policy\GroupChanges;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyChanges;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Roles;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Policy\Rule\RuleElements;
use YorvoID\Policy\StepUpForSensitiveChanges;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Change\RemoveRelation;
use YorvoID\Relations\RelationChanges;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\RelationPermit;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\ResourceType;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Policy\Fixtures\AcmeDeclarations;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;

/**
 * M2-13 fix 2: giving a super role or a strict ability takes a recent proof, whatever permits it (M-1,
 * M-2, L-1). The audit's harnesses, against the evaluator as the bundle builds it.
 *
 * - recoverer: issues recovery links (strict)
 * - keyholder: rotates keys, by default (strict)
 * - manager: manages roles, abilities and relations
 * - vault: a keeper relates people and purges (strict); a purger purges
 * - safe, in a vault: its openers are the vault's keepers; box, in a safe: its crackers, the safe's openers, purge
 */
final class StepUpForSensitiveChangesTest extends TestCase
{
    private const string ROTATE = 'acme.keys.rotate';

    private Realm $realm;

    private InMemoryPolicyStore $policy;

    private InMemoryRelationStore $relations;

    private Roles $roles;

    private PolicyWorld $world;

    private StubStepUpCheck $stepUp;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->policy = new InMemoryPolicyStore();
        $this->relations = new InMemoryRelationStore();
        $this->stepUp = new StubStepUpCheck(proven: false);
        $this->world = new PolicyWorld();
        $schema = RelationSchema::of([new GroupRelations(), new class implements RelationDeclarations {
            public function types(): iterable
            {
                yield new ResourceType('vault', [
                    new RelationDefinition('parent'),
                    new RelationDefinition('keeper', grants: [YorvoIDDeclarations::RELATIONS_MANAGE, 'acme.pages.purge']),
                    new RelationDefinition('purger', grants: ['acme.pages.purge']),
                    new RelationDefinition('looker', grants: ['acme.pages.view']),
                ]);
                // A safe's openers are its vault's keepers; a box's crackers, its safe's openers.
                yield new ResourceType('safe', [
                    new RelationDefinition('vault'),
                    new RelationDefinition('opener', fromParents: ['vault' => 'keeper'], grants: ['acme.pages.view']),
                ]);
                yield new ResourceType('box', [
                    new RelationDefinition('safe'),
                    new RelationDefinition('cracker', fromParents: ['safe' => 'opener'], grants: ['acme.pages.purge']),
                ]);
            }
        }]);
        $catalogue = PolicyCatalogue::of([new AcmeDeclarations(), new YorvoIDDeclarations(), new class implements PolicyDeclarations {
            public function abilities(): iterable
            {
                yield new Ability('acme.keys.rotate', 'Rotate keys', 'acme', ['keyholder'], 'keys', strict: true);
            }

            public function roles(): iterable
            {
                yield new Role('keyholder', 'Key holder', 'acme');
                yield new Role('recoverer', 'Recoverer', 'acme');
            }
        }]);
        $this->roles = new Roles($catalogue, $this->policy, [], new Relations($schema, $this->relations, Relations::MAX_DEPTH));
        $this->policy->saveChanges($this->realm, new AbilityChanges([
            YorvoIDDeclarations::PRINCIPALS_RECOVER => ['recoverer'],
            YorvoIDDeclarations::ROLES_MANAGE => ['manager'],
            YorvoIDDeclarations::PERMISSIONS_MANAGE => ['manager'],
            YorvoIDDeclarations::RELATIONS_MANAGE => ['manager'],
        ]));
        $this->policy->saveGroup($this->realm, 'recoverers', 'Recoverers');
        $this->policy->assignGroup(new GroupRoleAssignment($this->realm, 'recoverers', 'recoverer', new \DateTimeImmutable()));
        $this->policy->saveGroup($this->realm, 'editors', 'Editors');
    }

    /**
     * EVAL-04: a role that has a strict ability, given to someone, to a group, through a group, or copied.
     */
    public function testGivingARoleWithAStrictAbilityTakesAProof(): void
    {
        $actor = $this->actor('manager', 'recoverer');
        $other = $this->id();
        $changes = [
            new AssignRole($this->realm, $other, 'recoverer'),
            new AssignGroupRole($this->realm, 'editors', 'recoverer'),
            new AddGroupMember($this->realm, 'recoverers', Subject::principal($other)),
            new CreateRole($this->realm, 'copy', 'Copy', from: 'recoverer'),
        ];

        foreach ($changes as $command) {
            $this->assertWantsAProof($this->decide($actor, $command), 'Giving the strict ability "yorvoid.principals.recover"', $command::class);
        }

        $this->stepUp->proven = true;

        foreach ($changes as $command) {
            self::assertSame(Verdict::Permit, $this->decide($actor, $command)->verdict, $command::class);
        }

        self::assertSame('yorvoid.role.assign', $this->stepUp->asked[0]->operation);
        self::assertSame(300, $this->stepUp->asked[0]->within->seconds);
    }

    /**
     * EVAL-03: resetting gives a revoked strict default back, by name or all of them.
     */
    public function testResettingAStrictDefaultBackTakesAProof(): void
    {
        $actor = $this->actor('manager', 'rotator', 'recoverer');
        $this->policy->saveRole($this->realm, 'rotator', 'Rotator');
        $this->policy->saveChanges($this->realm, new AbilityChanges(
            [...$this->policy->changes($this->realm)->grant, self::ROTATE => ['rotator'], 'acme.pages.publish' => ['rotator']],
            [self::ROTATE => ['keyholder'], 'acme.pages.publish' => ['editor']],
        ));

        foreach ([new ResetAbilities($this->realm, [self::ROTATE]), new ResetAbilities($this->realm)] as $command) {
            $this->assertWantsAProof($this->decide($actor, $command), 'Giving the strict ability "acme.keys.rotate"', 'Reset');
        }

        // Nothing strict comes back, nothing is asked.
        self::assertSame(Verdict::Permit, $this->decide($actor, new ResetAbilities($this->realm, ['acme.pages.publish']))->verdict);
        self::assertCount(2, $this->stepUp->asked);
    }

    /**
     * EVAL-05: the proof binds whatever permits the change: a rule, an ability policy, or a relation.
     */
    public function testEveryPermitIsHeldToTheProof(): void
    {
        $recoverer = $this->actor('recoverer');
        $assign = new AssignRole($this->realm, $this->id(), 'recoverer');
        $rule = new class implements Rule {
            public function name(): string
            {
                return 'acme.role_managers';
            }

            public function matches(Request $request): bool
            {
                return YorvoIDDeclarations::ROLES_MANAGE === $request->ability;
            }

            public function decide(Request $request): RuleAnswer
            {
                return RuleAnswer::permit('Role managers are named by the application.');
            }
        };
        $policy = new class implements AbilityPolicy {
            public function holds(Actor $actor, string $ability, Command $command): bool
            {
                return YorvoIDDeclarations::ROLES_MANAGE === $ability;
            }
        };
        $keeper = $this->actor();
        $vault = new ResourceRef('vault', 'v');
        \assert(null !== $keeper->principalId);
        $this->relations->add(new RelationTuple($this->realm, $vault, 'keeper', Subject::principal($keeper->principalId)));
        $routes = [
            'a rule' => [$recoverer, $assign, [...RuleElements::of($rule)], null, true, 'Giving the strict ability "yorvoid.principals.recover"'],
            'an ability policy' => [$recoverer, $assign, [], $policy, true, 'Giving the strict ability "yorvoid.principals.recover"'],
            // The relation permit is YorvoID's own, always there.
            'a relation' => [$keeper, new AddRelation($this->realm, $vault, 'purger', Subject::principal($this->id())), [], null, false, 'Giving the strict ability "acme.pages.purge"'],
        ];

        foreach ($routes as $route => [$actor, $command, $elements, $abilities, $added, $sensitive]) {
            $this->stepUp->proven = false;

            if ($added) {
                self::assertSame(Verdict::Deny, $this->decide($actor, $command)->verdict, \sprintf('%s: nothing else permits it.', $route));
                self::assertSame([], $this->decide($actor, $command)->remedies, $route);
            }

            $this->assertWantsAProof($this->decide($actor, $command, $elements, $abilities), $sensitive, $route);
            $this->stepUp->proven = true;
            $permitted = $this->decide($actor, $command, $elements, $abilities);
            self::assertSame(Verdict::Permit, $permitted->verdict, $route);
            self::assertSame(AuthorizedBy::Ability, $permitted->basis, $route);
        }
    }

    /**
     * Every part of what a change gives: what a link carries down, the roles above a new parent, and the
     * roles of the groups a group is within (R2).
     */
    public function testWhatFollowsFromAChangeTakesAProofToo(): void
    {
        $this->policy->saveRole($this->realm, 'cleaner', 'Cleaner');
        $this->policy->saveChanges($this->realm, new AbilityChanges([...$this->policy->changes($this->realm)->grant, 'acme.pages.purge' => ['cleaner']]));
        $top = new ResourceRef('vault', 'top');
        $this->relations->add(new RelationTuple($this->realm, new ResourceRef('vault', 'mid'), 'parent', new Subject($top)));
        $this->policy->assign(new RoleAssignment($this->realm, $this->id(), 'cleaner', new \DateTimeImmutable(), $top));
        // inner is within outer, whose role is super.
        $this->policy->saveGroup($this->realm, 'outer', 'Outer');
        $this->policy->saveGroup($this->realm, 'inner', 'Inner');
        $this->policy->assignGroup(new GroupRoleAssignment($this->realm, 'outer', 'admin', new \DateTimeImmutable()));
        $this->relations->add(new RelationTuple($this->realm, GroupRelations::group('outer'), GroupRelations::MEMBER, GroupRelations::members('inner')));
        $changes = [
            'carried down' => [$this->actor('manager', 'cleaner'), new AddRelation($this->realm, new ResourceRef('safe', 's'), 'vault', new Subject(new ResourceRef('vault', 'v'))), 'Giving the strict ability "acme.pages.purge"'],
            'above a parent' => [$this->actor('manager', 'cleaner'), new AddRelation($this->realm, new ResourceRef('vault', 'new'), 'parent', new Subject(new ResourceRef('vault', 'mid'))), 'Giving the strict ability "acme.pages.purge"'],
            'within a group' => [$this->actor('manager', 'admin'), new AddGroupMember($this->realm, 'inner', Subject::principal($this->id())), 'Giving the super role "admin"'],
        ];

        foreach ($changes as $what => [$actor, $command, $sensitive]) {
            $this->stepUp->proven = false;
            $this->assertWantsAProof($this->decide($actor, $command), $sensitive, $what);
            $this->stepUp->proven = true;
            self::assertSame(Verdict::Permit, $this->decide($actor, $command)->verdict, $what);
        }
    }

    /**
     * The changes are held to the forbids whatever permits them: an ability policy alone doesn't run
     * them (R1).
     */
    public function testTheChangesArentRunWithoutTheForbids(): void
    {
        $everyone = new class implements AbilityPolicy {
            public function holds(Actor $actor, string $ability, Command $command): bool
            {
                return true;
            }
        };
        $log = new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions());
        $changing = array_values(array_filter($this->world->handlers, static fn (OperationHandler $handler): bool => $handler instanceof PolicyChanges || $handler instanceof GroupChanges || $handler instanceof RelationChanges || $handler instanceof AttributeChanges));
        self::assertCount(4, $changing);

        foreach ($changing as $handler) {
            $lacking = $handler instanceof AttributeChanges ? [[], [new StepUpForSensitiveChanges($this->roles)]] : [[], [new NoEscalation($this->roles)], [new StepUpForSensitiveChanges($this->roles)]];

            foreach ($lacking as $elements) {
                try {
                    new Operations(OperationCatalogue::of([$handler]), Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), $everyone, elements: $elements), $log, [$handler]);
                    self::fail(\sprintf('%s ran without its forbids.', $handler::class));
                } catch (IntegratorError $error) {
                    self::assertStringStartsWith(\sprintf('The handler %s needs the forbid "yorvoid.policy.', $handler::class), $error->getMessage());
                }
            }

            $catalogue = OperationCatalogue::of([$handler]);
            new Operations($catalogue, Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), $everyone, elements: [new NoEscalation($this->roles), new StepUpForSensitiveChanges($this->roles)]), $log, [$handler]);

            try {
                new Operations($catalogue, new class implements Authorization {
                    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
                    {
                        return Decision::deny('Never.');
                    }
                }, $log, [$handler]);
                self::fail('Another authorization can\'t be checked for them.');
            } catch (IntegratorError $error) {
                self::assertSame(\sprintf('The handler %s needs forbids that only the Evaluator is checked for.', $handler::class), strtok($error->getMessage(), "\n"));
            }
        }
    }

    /**
     * Taking never waits for a proof, nor does giving what's neither super nor strict.
     */
    public function testTakingAndGivingNothingSensitiveTakeNoProof(): void
    {
        $actor = $this->actor('manager', 'recoverer');
        $other = $this->id();
        $this->policy->saveRole($this->realm, 'spare', 'Spare');
        $this->relations->add(new RelationTuple($this->realm, new ResourceRef('vault', 'v'), 'purger', Subject::principal($other)));
        $taking = [
            new UnassignRole($this->realm, $other, 'recoverer'),
            new RevokeAbilities($this->realm, 'recoverer', [YorvoIDDeclarations::PRINCIPALS_RECOVER]),
            new RemoveGroupMember($this->realm, 'recoverers', Subject::principal($other)),
            new DeleteRole($this->realm, 'spare'),
            new RemoveRelation($this->realm, new ResourceRef('vault', 'v'), 'purger', Subject::principal($other)),
        ];
        $forbid = new StepUpForSensitiveChanges($this->roles, $this->stepUp);

        foreach ($taking as $command) {
            self::assertFalse($forbid->matches($this->request($actor, $command)), $command::class);
        }

        // Setting abilities can give, so it's weighed: taking only, it asks nothing.
        self::assertSame(Verdict::Permit, $this->decide($actor, new SetAbilities($this->realm, 'recoverer', []))->verdict);

        foreach ([new AssignRole($this->realm, $other, 'member'), new GrantAbilities($this->realm, 'editor', ['acme.pages.view']), new SetAbilities($this->realm, 'recoverer', [YorvoIDDeclarations::PRINCIPALS_RECOVER, 'acme.pages.view']), new CreateRole($this->realm, 'blank', 'Blank'), new AddRelation($this->realm, new ResourceRef('vault', 'v'), 'looker', Subject::principal($other))] as $command) {
            $decision = $this->decide($actor, $command);
            self::assertSame(Verdict::Permit, $decision->verdict, $command::class.': '.$decision->reason);
        }

        self::assertSame([], $this->stepUp->asked);
        self::assertFalse($forbid->matches($this->request(new Operator(), new AssignRole($this->realm, $other, 'recoverer'))), 'The operator is the final authority.');
    }

    /**
     * Taking needs no proof, but no-escalation still weighs it: a group's roles, taken from a member.
     */
    public function testTakingIsStillWeighed(): void
    {
        $other = $this->id();
        $removed = $this->decide($this->actor('manager'), new RemoveGroupMember($this->realm, 'recoverers', Subject::principal($other)));
        self::assertSame('A forbid applies: They\'d change what they don\'t hold themselves: yorvoid.principals.recover.', $removed->reason);
        self::assertSame([], $removed->remedies);
        self::assertSame([], $this->stepUp->asked);
    }

    public function testAnAbilityThatIsntDeclaredIsntSensitive(): void
    {
        $grant = $this->request($this->actor('manager'), new GrantAbilities($this->realm, 'editor', ['acme.nothing']));

        self::assertSame('It gives no super role and no strict ability.', new StepUpForSensitiveChanges($this->roles, $this->stepUp)->evaluate($grant)->detail);
    }

    public function testBothForbidsWeighOneEffectPerRequest(): void
    {
        $request = $this->request($this->actor('manager'), new AssignRole($this->realm, $this->id(), 'recoverer'));
        $effects = Effects::of($this->roles);

        self::assertSame($effects, Effects::of($this->roles));
        self::assertNotSame($effects, Effects::of(new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), new InMemoryPolicyStore())));
        self::assertSame($effects->on($request), $effects->on($request));
        self::assertNotSame($effects->on($request), $effects->on($this->request($this->actor('manager'), new AssignRole($this->realm, $this->id(), 'recoverer'))));
    }

    /**
     * A remedy is a promise: none when something else would refuse anyway.
     */
    public function testAProofIsOfferedOnlyWhenItWouldBeEnough(): void
    {
        // No-escalation: they don't hold what they'd give.
        $lacking = $this->decide($this->actor('manager'), new AssignRole($this->realm, $this->id(), 'recoverer'));
        self::assertSame(Verdict::Deny, $lacking->verdict);
        self::assertSame([], $lacking->remedies);

        // Nothing permits it.
        $unpermitted = $this->decide($this->actor('recoverer'), new AssignRole($this->realm, $this->id(), 'recoverer'));
        self::assertSame(Verdict::Deny, $unpermitted->verdict);
        self::assertSame([], $unpermitted->remedies);
    }

    /**
     * After a recovery, the check's own remedy; a check that can't tell leaves it undecided.
     */
    public function testWhatTheCheckSaysIsWhatTheDecisionSays(): void
    {
        $wait = new WaitRemedy(new \DateTimeImmutable('2026-11-12 12:00:00 UTC'));
        $assign = new AssignRole($this->realm, $this->id(), 'recoverer');
        $actor = $this->actor('manager', 'recoverer');
        $held = $this->decide($actor, $assign, stepUp: self::check(Truth::fails('Recovered recently.', $wait)));
        self::assertSame([$wait], $held->remedies);
        self::assertStringEndsWith('takes a recent proof of who they are: recovered recently.', $held->reason);

        $unknown = $this->decide($actor, $assign, stepUp: self::check(Truth::unknown(IndeterminateReason::StoreUnreachable, 'down')));
        self::assertSame(Verdict::Indeterminate, $unknown->verdict);
        self::assertSame(Precedence::ForbidUnknown, $unknown->precedence);
        self::assertSame('A forbid couldn\'t be ruled out: down', $unknown->reason);

        $proved = $this->decide($actor, $assign, stepUp: self::check(Truth::holds('Proved recently.')));
        self::assertSame(Verdict::Permit, $proved->verdict);
        self::assertSame(['Giving the strict ability "yorvoid.principals.recover", and proved recently.'], array_values(array_map(static fn (Reason $reason): string => $reason->truth->detail, array_filter($proved->reasons, static fn (Reason $reason): bool => StepUpForSensitiveChanges::NAME === $reason->source))));
    }

    private function assertWantsAProof(Decision $decision, string $sensitive, string $what): void
    {
        self::assertSame(Verdict::Deny, $decision->verdict, $what);
        self::assertSame(Precedence::ForbidHolds, $decision->precedence, $what);
        self::assertSame(\sprintf('A forbid applies: %s takes a recent proof of who they are: not proved recently.', $sensitive), $decision->reason, $what);
        self::assertCount(1, $decision->remedies, $what);
        self::assertInstanceOf(StepUpRemedy::class, $decision->remedies[0], $what);
    }

    /**
     * @param Command<mixed>      $command
     * @param list<PolicyElement> $elements Beside YorvoID's own
     */
    private function decide(Actor $actor, Command $command, array $elements = [], ?AbilityPolicy $abilities = null, ?StepUpCheck $stepUp = null): Decision
    {
        $evaluator = Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), $abilities, elements: [
            new RolePermit($this->roles),
            new NoEscalation($this->roles),
            new StepUpForSensitiveChanges($this->roles, $stepUp ?? $this->stepUp),
            new RelationPermit($this->roles->relations() ?? throw new \LogicException()),
            ...$elements,
        ]);

        return $evaluator->decide($this->world->catalogue->forCommand($command::class), $command, $actor, new Origin(OriginKind::Web));
    }

    /**
     * @param Command<mixed> $command
     */
    private function request(Actor $actor, Command $command): Request
    {
        return Request::forOperation($this->world->catalogue->forCommand($command::class), $command, $actor, new Origin(OriginKind::Web), new \DateTimeImmutable());
    }

    private function actor(string ...$roles): ActingPrincipal
    {
        $id = $this->id();

        foreach ($roles as $role) {
            $this->policy->assign(new RoleAssignment($this->realm, $id, $role, new \DateTimeImmutable()));
        }

        return new ActingPrincipal($this->realm, $id);
    }

    private function id(): PrincipalId
    {
        return new PrincipalId(new UuidV7());
    }

    private static function check(Truth $truth): StepUpCheck
    {
        return new readonly class($truth) implements StepUpCheck {
            public function __construct(
                private Truth $truth,
            ) {
            }

            public function check(Actor $actor, StepUpRemedy $required): Truth
            {
                return $this->truth;
            }
        };
    }
}
