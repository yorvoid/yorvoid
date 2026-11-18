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
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Verdict;
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

        $cases = [
            'unknown_session' => $this->session(AuthenticationMethod::Passkey),
            'session_ended' => $ended,
            'session_expired' => $expired,
            'another principal' => $this->session(AuthenticationMethod::Passkey, $someoneElses->id),
        ];

        foreach ($cases as $case => $session) {
            $decision = $this->decide($session);
            self::assertSame(Verdict::Deny, $decision->verdict, $case);
            self::assertStringContainsString('session', $decision->reason, $case);
        }
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

        self::assertCount(1, array_filter($logger->of('warning'), static fn (array $entry): bool => str_contains($entry[1], 'can\'t prove actors')));
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
