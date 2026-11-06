<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Invitation\AcceptInvitation;
use YorvoID\Authn\Invitation\Invitations;
use YorvoID\Authn\Invitation\Invite;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Setup\ClaimSetup;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\Setup\SetupState;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\LosingSessionStore;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Throttle;

/**
 * The branches only a real race reaches (STATIC-12): another request changed the row between the
 * service's check and its conditional write. Each store here loses that write, as the loser of a race
 * would; the service refuses and changes nothing.
 */
final class LostRaceTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private SecurityLog $log;

    private Principals $principals;

    protected function setUp(): void
    {
        $this->stack = $this->stack('memory');
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $this->log = new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $this->log, $this->stack->transactions, clock: $this->clock);
    }

    public function testTheLoserOfTwoClaimsCreatesNoPrincipal(): void
    {
        $setup = $this->stack->setup;
        $losing = new readonly class($setup) implements SetupStore {
            public function __construct(private SetupStore $store)
            {
            }

            public function get(Realm $realm): ?SetupState
            {
                return $this->store->get($realm);
            }

            public function replaceCode(Realm $realm, string $codeHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): bool
            {
                return $this->store->replaceCode($realm, $codeHash, $createdAt, $expiresAt);
            }

            public function claim(Realm $realm, string $codeHash, PrincipalId $by, \DateTimeImmutable $at): bool
            {
                return false;
            }
        };
        $codes = new SetupCodes($losing, $this->principals, $this->log, $this->stack->transactions, new Attempts(new Throttle($this->stack->throttle, $this->clock)), new Sessions($this->stack->sessions, $this->principals, $this->log, $this->stack->transactions, clock: $this->clock), $this->clock);
        $code = Permits::call($codes->create(...), new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console));

        try {
            Permits::call($codes->claim(...), new ClaimSetup(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'ana@example.com')), new Origin(OriginKind::Web));
            self::fail('The loser of the race claimed the realm.');
        } catch (InvalidSetupCode) {
        }

        self::assertNull($this->principals->findByIdentifier(new Realm('acme'), \YorvoID\Core\Principal\IdentifierKind::Email, 'ana@example.com'), 'The loser\'s principal was kept.');
    }

    public function testASessionThatEndedMeanwhileGetsNoNewTokenNorProof(): void
    {
        $sessions = new Sessions(new LosingSessionStore($this->stack->sessions), $this->principals, $this->log, $this->stack->transactions, clock: $this->clock);
        $principal = Permits::call($this->principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $started = Permits::call($sessions->start(...), new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now()))), new Origin(OriginKind::Web));
        $entries = iterator_count($this->stack->log->entries(new Realm('acme'), 1));

        self::assertNull($sessions->refresh(new Realm('acme'), $started->session->id, new Origin(OriginKind::Web)));
        self::assertNull($sessions->stepUp(new Realm('acme'), $started->session->id, new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), operation: 'acme.site.remove'), new Origin(OriginKind::Web)));
        self::assertSame($entries, iterator_count($this->stack->log->entries(new Realm('acme'), 1)), 'A step-up that lost was recorded.');
        self::assertNotNull($sessions->validate(new Realm('acme'), $started->token), 'The old token stopped working.');
    }

    public function testAnInvitationUsedMeanwhileActivatesNobody(): void
    {
        $tokens = new OneTimeTokens($this->stack->tokens, $this->principals, $this->log, $this->stack->transactions, clock: $this->clock);
        $invitation = Permits::call(new Invitations($this->principals, $tokens, $this->stack->transactions, new Throttle($this->stack->throttle, $this->clock), new Sessions($this->stack->sessions, $this->principals, $this->log, $this->stack->transactions, clock: $this->clock))->invite(...), new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Console));
        $losing = new readonly class($this->stack->tokens) implements OneTimeTokenStore {
            public function __construct(private OneTimeTokenStore $store)
            {
            }

            public function add(OneTimeToken $token, string $verifierHash): void
            {
                $this->store->add($token, $verifierHash);
            }

            public function find(Realm $realm, string $selector, string $purpose): ?array
            {
                return $this->store->find($realm, $selector, $purpose);
            }

            public function delete(Realm $realm, string $selector): bool
            {
                return false;
            }

            public function deleteFor(Realm $realm, PrincipalId $principalId, string $purpose): int
            {
                return $this->store->deleteFor($realm, $principalId, $purpose);
            }

            public function purge(\DateTimeImmutable $now): int
            {
                return $this->store->purge($now);
            }
        };
        $losingTokens = new OneTimeTokens($losing, $this->principals, $this->log, $this->stack->transactions, clock: $this->clock);
        $invitations = new Invitations($this->principals, $losingTokens, $this->stack->transactions, new Throttle($this->stack->throttle, $this->clock), new Sessions($this->stack->sessions, $this->principals, $this->log, $this->stack->transactions, clock: $this->clock));

        self::assertNull(Permits::call($invitations->accept(...), new AcceptInvitation(new Realm('acme'), $invitation->token->secret), new Origin(OriginKind::Web)));
        self::assertSame(PrincipalStatus::Invited, $this->principals->get(new Realm('acme'), $invitation->principal->id)?->status);
    }
}
