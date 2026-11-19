<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Session;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\Storage\InMemorySessionStore;
use YorvoID\Authn\Session\StoredSessions;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\ProvesActors;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\RecordingLogger;

/**
 * Every decision reads its session from the store (D-45, AR-05): one that isn't on record, has ended or
 * expired, or is another principal's, is refused, and the decision reads the stored session's factors,
 * never the ones it was handed.
 */
final class StoredSessionsTest extends TestCase
{
    private MockClock $clock;

    private InMemorySessionStore $store;

    private Realm $realm;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-18 16:00:00 UTC');
        $this->store = new InMemorySessionStore();
        $this->realm = new Realm('acme');
    }

    public function testASessionOnRecordDecidesByItsStoredFactors(): void
    {
        $stored = $this->session(AuthenticationMethod::Password);
        $this->store->add($stored, 'hash');
        // The same session, claiming a passkey it never used.
        $forged = $this->session(AuthenticationMethod::Passkey, $stored->id, $stored->principalId);

        self::assertSame(Verdict::Deny, $this->decide($forged)->verdict, 'A forged factor counted.');
        self::assertSame(Verdict::Deny, $this->decide($stored)->verdict);
        $this->store->add($passkey = $this->session(AuthenticationMethod::Passkey), 'other');
        self::assertSame(Verdict::Permit, $this->decide($passkey)->verdict);
    }

    public function testASessionThatIsntWhatItClaimsIsRefused(): void
    {
        $ended = $this->session(AuthenticationMethod::Passkey);
        $this->store->add($ended, 'ended');
        $this->store->end($this->realm, $ended->id, $this->clock->now(), SessionEndReason::SignedOut);
        $expired = $this->session(AuthenticationMethod::Passkey, expires: '-1 minute');
        $this->store->add($expired, 'expired');
        $someoneElses = $this->session(AuthenticationMethod::Passkey);
        $this->store->add($someoneElses, 'someone');

        // An ended session is past its expiry as well: each is refused for what it is.
        $cases = [
            'unknown_session' => [$this->session(AuthenticationMethod::Passkey), 'isn\'t on record'],
            'session_ended' => [$ended, 'has ended'],
            'session_expired' => [$expired, 'has expired'],
            'another principal' => [$this->session(AuthenticationMethod::Passkey, $someoneElses->id), 'isn\'t on record'],
        ];

        foreach ($cases as $case => [$session, $why]) {
            $decision = $this->decide($session);
            self::assertSame(Verdict::Deny, $decision->verdict, $case);
            self::assertStringContainsString($why, $decision->reason, $case);
        }
    }

    /**
     * A view-as is proven by its viewer: the stored session views, an ended one doesn't.
     */
    public function testAViewAsIsProvenByItsViewer(): void
    {
        $viewer = $this->session(AuthenticationMethod::Passkey);
        $this->store->add($viewer, 'viewer');
        $target = $this->session(AuthenticationMethod::Password);
        $this->store->add($target, 'target');
        $evaluator = $this->evaluator();

        $view = $evaluator->decideAbility(new ViewingAs($viewer, $target), 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));
        self::assertNotContains(IndeterminateReason::UnprovenActor, $view->indeterminate, 'The view-as wasn\'t proven by its viewer.');

        // The target may: what's refused is the viewer.
        $this->store->add($target = $this->session(AuthenticationMethod::Passkey), 'passkey target');
        $this->store->end($this->realm, $viewer->id, $this->clock->now(), SessionEndReason::SignedOut);
        $ended = $evaluator->decideAbility(new ViewingAs($viewer, $target), 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));
        self::assertSame(Verdict::Deny, $ended->verdict);
        self::assertSame(Verdict::Deny, $evaluator->decideAbilities(new ViewingAs($viewer, $target), 'acme.badges.issue', $this->realm, [[null, null]], new Origin(OriginKind::Web))[0]->verdict, 'In a batch, the ended viewer still viewed.');
        // Nor does a viewer nothing proves.
        $unknown = $evaluator->decideAbility(new ViewingAs($this->session(AuthenticationMethod::Passkey), $target), 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));
        self::assertSame(Verdict::Deny, $unknown->verdict);
        self::assertContains(\sprintf('The session %s has ended.', $viewer->id), array_map(static fn (Reason $reason): string => $reason->truth->detail, $ended->reasons));
    }

    /**
     * A prover that fails, as a store that's down, leaves the decision unknown; a mistake in the setup
     * is thrown.
     */
    public function testAProverThatFailsLeavesTheDecisionUnknown(): void
    {
        $failing = static fn (\Throwable $failure): ProvesActors => new readonly class($failure) implements ProvesActors {
            public function __construct(private \Throwable $failure)
            {
            }

            public function proves(Actor $actor): bool
            {
                return true;
            }

            public function prove(Actor $actor): Actor|Truth
            {
                throw $this->failure;
            }
        };
        $session = $this->session(AuthenticationMethod::Passkey);
        $decide = fn (\Throwable $failure): \YorvoID\Core\Operation\Decision => Evaluator::builtIn(new ValidAuthority(), clock: $this->clock, actors: [$failing($failure)])
            ->decideAbility($session, 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));

        $down = $decide(new \RuntimeException('The store is down.'));
        self::assertSame([Verdict::Indeterminate, [IndeterminateReason::EvaluationError]], [$down->verdict, $down->indeterminate]);

        $this->expectException(IntegratorError::class);
        $decide(new IntegratorError('Set up wrong.', 'Set it up right.', 'concepts/authorization.md'));
    }

    public function testAnActorNothingProvesIsIndeterminateAndWarnedAboutOnce(): void
    {
        $logger = new RecordingLogger();
        $actor = new readonly class(new Realm('acme'), new PrincipalId(new UuidV7())) implements Actor {
            public function __construct(public ?Realm $realm, public ?PrincipalId $principalId)
            {
            }
        };
        $evaluator = $this->evaluator($logger);

        foreach ([1, 2] as $_) {
            $decision = $evaluator->decideAbility($actor, 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));
            self::assertSame([Verdict::Indeterminate, [IndeterminateReason::UnprovenActor]], [$decision->verdict, $decision->indeterminate]);
        }

        $warnings = array_values(array_filter($logger->of('warning'), static fn (array $entry): bool => str_contains($entry[1], 'can\'t prove actors')));
        self::assertCount(1, $warnings);
        self::assertSame(['class' => $actor::class], $warnings[0][2]);
    }

    private function decide(Session $session): \YorvoID\Core\Operation\Decision
    {
        return $this->evaluator()->decideAbility($session, 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));
    }

    private function evaluator(?RecordingLogger $logger = null): Evaluator
    {
        // Permits whoever signed in with a passkey.
        $passkeyHolders = new class implements PermitElement {
            public function name(): string
            {
                return 'passkey_holders';
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
                $actor = $request->actor;

                return $actor instanceof Session && \in_array(AuthenticationMethod::Passkey, $actor->authentication->methods(), true)
                    ? Truth::holds('Signed in with a passkey.')
                    : Truth::fails('Not signed in with a passkey.');
            }
        };

        return Evaluator::builtIn(new ValidAuthority(), elements: [$passkeyHolders], clock: $this->clock, logger: $logger, actors: [new StoredSessions($this->store, $this->clock)]);
    }

    private function session(AuthenticationMethod $method, ?SessionId $id = null, ?PrincipalId $principal = null, string $expires = '+1 hour'): Session
    {
        $now = $this->clock->now();

        return new Session($id ?? new SessionId(new UuidV7()), $this->realm, $principal ?? new PrincipalId(new UuidV7()), 0, new AuthenticationContext(new AuthenticationFactor($method, $now, AuthenticationMethod::Passkey === $method, AuthenticationMethod::Passkey === $method)), $now, $now, $now->modify($expires), $now->modify($expires));
    }
}
