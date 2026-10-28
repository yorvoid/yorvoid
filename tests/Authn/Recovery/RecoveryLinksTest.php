<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Recovery;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;

/**
 * Operator-issued recovery links on every implementation (ATN-033, ATN-029).
 */
final class RecoveryLinksTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private Principals $principals;

    private Sessions $sessions;

    private StepUp $stepUp;

    #[DataProvider('implementations')]
    public function testARecoveryLinkEndsEverySessionAndStartsANewOne(string $implementation): void
    {
        $links = $this->links($implementation);
        $owner = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $attackers = $this->sessions->start(new StartSession(new Realm('acme'), $owner->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now()))), new Origin(OriginKind::Web));

        $link = $links->issue(new IssueRecoveryLink(new Realm('acme'), $owner->id), new Origin(OriginKind::Console, 'owner:recover'));

        self::assertSame(RecoveryLinks::PURPOSE, $link->token->purpose);
        self::assertNull($link->token->email, 'An operator hands the link over: it isn\'t sent by email.');
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:00:30 UTC'), $link->token->expiresAt, '15 minutes by default.');

        $started = $links->redeem(new Realm('acme'), $link->secret, new Origin(OriginKind::Web), 'Firefox on Linux', '192.0.2.10');

        self::assertNotNull($started);
        self::assertSame([AuthenticationMethod::RecoveryLink], $started->session->authentication->methods());
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$started->session->device, $started->session->address]);
        self::assertNotNull($this->sessions->validate(new Realm('acme'), $started->token));
        self::assertNull($this->sessions->validate(new Realm('acme'), $attackers->token), 'A session from before the recovery survived it.');
        self::assertSame(SessionEndReason::Recovered, $this->sessions->get(new Realm('acme'), $attackers->session->id)?->endReason);
        self::assertNull($links->redeem(new Realm('acme'), $link->secret, new Origin(OriginKind::Web)), 'A recovery link worked twice.');
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:45:30 UTC'), $this->stepUp->coolDownEndsAt(new Realm('acme'), $owner->id), 'The recovery started no cool-down.');
    }

    #[DataProvider('implementations')]
    public function testARecoveryLinkExpiresSoon(string $implementation): void
    {
        $links = $this->links($implementation);
        $owner = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $link = $links->issue(new IssueRecoveryLink(new Realm('acme'), $owner->id, Duration::minutes(5)), new Origin(OriginKind::Console));

        $this->clock->sleep(300);

        self::assertNull($links->redeem(new Realm('acme'), $link->secret, new Origin(OriginKind::Web)));
        self::assertNull($this->stepUp->coolDownEndsAt(new Realm('acme'), $owner->id), 'A link that didn\'t work started a cool-down.');
    }

    public function testOnlyAnActivePrincipalCanRecover(): void
    {
        $links = $this->links('memory');
        $invited = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Invited), new Origin(OriginKind::Console));

        try {
            $links->issue(new IssueRecoveryLink(new Realm('acme'), $invited->id), new Origin(OriginKind::Console));
            self::fail('An invited principal got a recovery link.');
        } catch (InactivePrincipal $exception) {
            self::assertStringEndsWith('can\'t recover access; activate it first.', $exception->getMessage());
        }

        $this->expectException(UnknownPrincipal::class);

        $links->issue(new IssueRecoveryLink(new Realm('acme'), new PrincipalId(new UuidV7Generator()->next())), new Origin(OriginKind::Console));
    }

    public function testIssuingIsAnOperation(): void
    {
        self::assertSame('yorvoid.principals.recover', new OperationCatalogue([IssueRecoveryLink::class])->get('yorvoid.recovery_link.issue')->ability);
    }

    private function links(string $implementation): RecoveryLinks
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, clock: $this->clock);
        $this->stepUp = new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock, Duration::hours(24));

        return new RecoveryLinks(new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, clock: $this->clock), $this->sessions, $this->principals, $this->stepUp, $this->stack->transactions, $this->clock);
    }
}
