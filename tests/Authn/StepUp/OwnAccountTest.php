<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\StepUp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpSelfService;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\RevokeTokens;
use YorvoID\Core\Authorization\BuiltIn\SelfServiceForOwnAccount;
use YorvoID\Core\Authorization\BuiltIn\SelfServicePermit;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NoSelfService;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\ActivatePrincipal;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\RemoveIdentifier;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Authn\StepUp\Fixtures\SetPreferences;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Support\Permits;

/**
 * M2-13 fix 5 (H-4): a change to one's own account asks what self-service asks of it, whatever permits
 * it: a principals manager is held as a plain principal is. The architect's grid: each own-account
 * operation, as a manager and as a plain principal, inside the recovery hold, with a stale session, and
 * fresh.
 */
final class OwnAccountTest extends TestCase
{
    /** What asks a recent proof and waits out the hold, on one's own account */
    private const array HELD = ['yorvoid.identifier.set', 'yorvoid.identifier.remove', 'yorvoid.principal.suspend', 'yorvoid.principal.delete', 'yorvoid.recovery_link.issue', 'yorvoid.token.issue'];

    /** What the Self basis permits too: a plain principal runs it on their own account */
    private const array SELF = ['yorvoid.identifier.set', 'yorvoid.identifier.remove', 'yorvoid.session.end_all'];

    private Realm $realm;

    private MockClock $clock;

    private Principals $principals;

    private Sessions $sessions;

    private StepUp $stepUp;

    /** @var list<PrincipalId> Who holds every ability */
    private array $managers = [];

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->clock = new MockClock('2026-11-12 18:00:00 UTC');
        $memory = new InMemoryStorage();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($memory->log, $memory->transactions, ids: $ids, clock: $this->clock);
        $this->principals = new Principals($memory->principals, $memory->identifiers, $log, $memory->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($memory->sessions, $this->principals, $log, $memory->transactions, ids: $ids, clock: $this->clock);
        $this->stepUp = new StepUp($this->sessions, $memory->coolDowns, $log, $this->clock, Duration::hours(24));
        $this->authority = new PrincipalAuthority($memory->principals);
    }

    private PrincipalAuthority $authority;

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function actorsAndStates(): iterable
    {
        foreach (['manager' => true, 'plain' => false] as $who => $manager) {
            foreach (['hold', 'stale', 'fresh'] as $state) {
                yield $who.', '.$state => [$manager, $state];
            }
        }
    }

    #[DataProvider('actorsAndStates')]
    public function testOnesOwnAccountAsksWhatSelfServiceAsks(bool $manager, string $state): void
    {
        $session = $this->actor($manager, $state);
        $evaluator = $this->evaluator();

        foreach ($this->ownCommands($session->principalId) as $command) {
            $operation = $this->catalogue()->forCommand($command::class)->name;
            $decision = $this->decide($evaluator, $command, $session);
            $held = \in_array($operation, self::HELD, true);
            $permitted = $manager || \in_array($operation, self::SELF, true);
            $what = \sprintf('%s, %s', $operation, $state);

            if (!$permitted) {
                self::assertSame(Verdict::Deny, $decision->verdict, $what.': nothing permits it.');
                self::assertSame([], $decision->remedies, $what);
            } elseif ($held && 'hold' === $state) {
                self::assertSame(Verdict::Deny, $decision->verdict, $what);
                self::assertCount(1, $decision->remedies, $what);
                self::assertInstanceOf(WaitRemedy::class, $decision->remedies[0], $what);
            } elseif ($held && 'stale' === $state) {
                self::assertSame(Verdict::Deny, $decision->verdict, $what);
                self::assertCount(1, $decision->remedies, $what);
                self::assertInstanceOf(StepUpRemedy::class, $decision->remedies[0], $what);
                self::assertSame($operation, $decision->remedies[0]->operation, $what);
            } else {
                self::assertSame(Verdict::Permit, $decision->verdict, $what.': '.$decision->reason);
            }
        }
    }

    /**
     * The rule is about one's own: a manager changes someone else's account as before, held or stale.
     */
    public function testSomeoneElsesAccountIsntOnesOwn(): void
    {
        $other = $this->actor(false, 'fresh')->principalId;
        $evaluator = $this->evaluator();

        foreach (['hold', 'stale'] as $state) {
            $manager = $this->actor(true, $state);

            foreach ($this->ownCommands($other) as $command) {
                $decision = $this->decide($evaluator, $command, $manager);
                self::assertSame(Verdict::Permit, $decision->verdict, $command::class.', '.$state.': '.$decision->reason);
            }
        }
    }

    public function testTheOperatorIsntAsked(): void
    {
        $principal = $this->actor(true, 'hold')->principalId;

        foreach ($this->ownCommands($principal) as $command) {
            self::assertSame(Verdict::Permit, $this->decide($this->evaluator(), $command, new Operator(), OriginKind::Console)->verdict, $command::class);
        }
    }

    /**
     * H-4, the audit's run: a manager who recovered an account moves its email address.
     */
    public function testAManagerWhoRecoveredDoesntMoveTheEmail(): void
    {
        $session = $this->actor(true, 'hold');

        foreach ([new SetIdentifier($this->realm, $session->principalId, IdentifierKind::Email, 'thief@example.net'), new RemoveIdentifier($this->realm, $session->principalId, IdentifierKind::Email)] as $command) {
            $decision = $this->decide($this->evaluator(), $command, $session);
            self::assertSame(Verdict::Deny, $decision->verdict);
            self::assertSame('A forbid applies: On one\'s own account: the account was recovered recently.', $decision->reason);
        }
    }

    /**
     * C1: an evaluator built without builtIn() holds one's own account too; without a self-service policy,
     * nobody changes their own.
     */
    public function testEveryEvaluatorHoldsOnesOwnAccount(): void
    {
        $session = $this->actor(true, 'fresh');
        $byRole = new Evaluator([$this->managers()], $this->authority, $this->clock);
        $own = $this->decide($byRole, new SetIdentifier($this->realm, $session->principalId, IdentifierKind::Email, 'ana@example.org'), $session);
        self::assertNotSame(Verdict::Permit, $own->verdict, 'No self-service policy: nobody changes their own.');
        self::assertSame('A forbid couldn\'t be ruled out: No self-service policy says what "yorvoid.identifier.set" needs.', $own->reason);

        $other = $this->actor(false, 'fresh')->principalId;
        self::assertSame(Verdict::Permit, $this->decide($byRole, new SetIdentifier($this->realm, $other, IdentifierKind::Email, 'bob@example.org'), $session)->verdict, 'Someone else changes it.');

        $policy = new StepUpSelfService($this->stepUp);
        new Evaluator([new SelfServicePermit($policy), new SelfServiceForOwnAccount($policy)], $this->authority);

        foreach ([[new SelfServicePermit($policy), new SelfServiceForOwnAccount(new NoSelfService())], [new SelfServiceForOwnAccount($policy), $this->impostor()]] as $elements) {
            try {
                new Evaluator($elements, $this->authority);
                self::fail('Another own-account forbid.');
            } catch (IntegratorError $error) {
                self::assertSame('The policy element "yorvoid.self_service.own_account" isn\'t the self-service permit\'s own.', strtok($error->getMessage(), "\n"));
            }
        }
    }

    /**
     * C2: an application's command about a principal asks nothing of one's own, unless the application
     * says so.
     */
    public function testAnApplicationsCommandAsksOnlyWhatItsGiven(): void
    {
        $session = $this->actor(true, 'stale');
        $command = new SetPreferences($this->realm, $session->principalId);

        self::assertSame(Verdict::Permit, $this->decide($this->evaluator(), $command, $session)->verdict);

        $asking = $this->evaluator(['acme.preferences.set' => new AuthenticationRequirement(Duration::minutes(5), operation: 'acme.preferences.set')]);
        $decision = $this->decide($asking, $command, $session);
        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertInstanceOf(StepUpRemedy::class, $decision->remedies[0] ?? null);
    }

    /**
     * An application's own self-service operation, unknown to the policy, needs a recent proof, not the
     * hold, as it did on the Self basis.
     */
    public function testAnApplicationsSelfServiceAsksARecentProof(): void
    {
        foreach ([true, false] as $manager) {
            $stale = $this->actor($manager, 'stale');
            $decision = $this->decide($this->evaluator(), new IssueBadge($this->realm, $stale->principalId), $stale);
            self::assertSame(Verdict::Deny, $decision->verdict);
            self::assertInstanceOf(StepUpRemedy::class, $decision->remedies[0] ?? null);
            self::assertSame('acme.badge.issue', $decision->remedies[0]->operation);

            $held = $this->actor($manager, 'hold');
            self::assertSame(Verdict::Permit, $this->decide($this->evaluator(), new IssueBadge($this->realm, $held->principalId), $held)->verdict, 'Not held.');
        }
    }

    /**
     * What a job acts as isn't a session: on its own account, only what asks nothing.
     */
    public function testOnlyASessionShowsWhatsAsked(): void
    {
        $principal = $this->actor(true, 'fresh')->principalId;
        $job = new \YorvoID\Core\Operation\ActingPrincipal($this->realm, $principal);
        $evaluator = $this->evaluator();

        self::assertSame(Verdict::Deny, $this->decide($evaluator, new SuspendPrincipal($this->realm, $principal), $job)->verdict);
        self::assertSame(Verdict::Permit, $this->decide($evaluator, new RevokeTokens($this->realm, $principal, 'acme.api_key'), $job)->verdict);
        self::assertSame(Verdict::Permit, $this->decide($evaluator, new SetPreferences($this->realm, $principal), $job)->verdict);
    }

    /**
     * @return list<Command<mixed>>
     */
    private function ownCommands(PrincipalId $principal): array
    {
        return [
            new SetIdentifier($this->realm, $principal, IdentifierKind::Email, 'new@example.org'),
            new RemoveIdentifier($this->realm, $principal, IdentifierKind::Email),
            new SuspendPrincipal($this->realm, $principal),
            new DeletePrincipal($this->realm, $principal),
            new IssueRecoveryLink($this->realm, $principal),
            new IssueToken($this->realm, $principal, 'acme.api_key', Duration::days(1)),
            new EndSessions($this->realm, $principal, SessionEndReason::SignedOut),
            new RevokeTokens($this->realm, $principal, 'acme.api_key'),
            new ActivatePrincipal($this->realm, $principal),
        ];
    }

    /**
     * A session of a new principal, a manager or not: proved now (fresh), five minutes and a second ago
     * (stale), or proved now after a recovery (hold).
     */
    private function actor(bool $manager, string $state): Session
    {
        $principal = Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, email: bin2hex(random_bytes(4)).'@example.com'), new Origin(OriginKind::Console));

        if ($manager) {
            $this->managers[] = $principal->id;
        }

        $session = Permits::call($this->sessions->start(...), new StartSession($this->realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true))), new Origin(OriginKind::Web))->session;

        match ($state) {
            'hold' => $this->stepUp->startCoolDown($this->realm, $principal->id, new Origin(OriginKind::Web)),
            'stale' => $this->clock->sleep(301),
            default => null,
        };

        return $session;
    }

    /**
     * @param array<string, AuthenticationRequirement|null> $requirements
     */
    private function evaluator(array $requirements = []): Evaluator
    {
        $managers = fn (): array => $this->managers;

        return Evaluator::builtIn($this->authority, new readonly class($managers) implements AbilityPolicy {
            /**
             * @param \Closure(): list<PrincipalId> $managers
             */
            public function __construct(
                private \Closure $managers,
            ) {
            }

            public function holds(Actor $actor, string $ability, Command $command): bool
            {
                return null !== $actor->principalId && [] !== array_filter(($this->managers)(), $actor->principalId->equals(...));
            }
        }, new StepUpSelfService($this->stepUp, $requirements), commandLine: true, clock: $this->clock);
    }

    /**
     * A permit by role for the managers, as RolePermit would give it.
     */
    private function managers(): PermitElement
    {
        $managers = fn (): array => $this->managers;

        return new readonly class($managers) implements PermitElement {
            /**
             * @param \Closure(): list<PrincipalId> $managers
             */
            public function __construct(
                private \Closure $managers,
            ) {
            }

            public function name(): string
            {
                return 'acme.managers';
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Ability;
            }

            public function matches(Request $request): bool
            {
                return null !== $request->actor->principalId;
            }

            public function evaluate(Request $request): Truth
            {
                \assert(null !== $request->actor->principalId);

                return [] !== array_filter(($this->managers)(), $request->actor->principalId->equals(...)) ? Truth::holds('A manager.') : Truth::fails('Not a manager.');
            }
        };
    }

    private function impostor(): PermitElement
    {
        return new SelfServicePermit(new NoSelfService());
    }

    /**
     * @param Command<mixed> $command
     */
    private function decide(Evaluator $evaluator, Command $command, Actor $actor, OriginKind $from = OriginKind::Web): Decision
    {
        return $evaluator->decide($this->catalogue()->forCommand($command::class), $command, $actor, new Origin($from));
    }

    private function catalogue(): OperationCatalogue
    {
        return new OperationCatalogue([SetIdentifier::class, RemoveIdentifier::class, SuspendPrincipal::class, ActivatePrincipal::class, CreatePrincipal::class, DeletePrincipal::class, IssueRecoveryLink::class, IssueToken::class, RevokeTokens::class, EndSessions::class, EndSession::class, StartSession::class, SetPreferences::class, IssueBadge::class]);
    }
}
