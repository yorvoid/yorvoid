<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Token;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Token\Event\OneTimeTokenConsumed;
use YorvoID\Authn\Token\Event\OneTimeTokenIssued;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\RevokeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\RemoveIdentifier;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;

/**
 * One-time tokens on every implementation (ATN-030). How tokens behave over time, under newest-only,
 * and when used, is also checked against the formal model: see OneTimeTokenTracesTest.
 */
final class OneTimeTokensTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private RecordingDispatcher $events;

    private Principals $principals;

    #[DataProvider('implementations')]
    public function testIssuesATokenThatWorksOnce(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $principal = $this->principal(email: 'ana@example.com');
        $actor = new PrincipalId(new UuidV7Generator()->next());

        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.password_reset', Duration::hours(1), 'Ana@Example.com'), new Origin(OriginKind::Web), $actor);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$/D', $issued->secret->reveal(), 'A 128-bit selector and a 256-bit verifier, as Quire\'s.');
        self::assertSame(substr($issued->secret->reveal(), 0, 32), $issued->token->selector);
        self::assertEquals(new OneTimeToken($issued->token->selector, new Realm('acme'), $principal->id, 'acme.password_reset', 'ana@example.com', new \DateTimeImmutable('2026-10-27 18:45:30 UTC'), new \DateTimeImmutable('2026-10-27 19:45:30 UTC')), $issued->token);

        self::assertEquals($issued->token, $tokens->peek(new Realm('acme'), 'acme.password_reset', $issued->secret));
        self::assertEquals($issued->token, $tokens->peek(new Realm('acme'), 'acme.password_reset', $issued->secret), 'Peeking used it.');
        self::assertEquals($issued->token, $tokens->consume(new Realm('acme'), 'acme.password_reset', $issued->secret, new Origin(OriginKind::Web)));
        self::assertNull($tokens->consume(new Realm('acme'), 'acme.password_reset', $issued->secret, new Origin(OriginKind::Web)), 'A token worked twice.');
        self::assertNull($tokens->peek(new Realm('acme'), 'acme.password_reset', $issued->secret));

        $entries = \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), 1);
        self::assertSame(['yorvoid.token.issue', 'yorvoid.token.consume'], array_map(static fn ($entry): string => $entry->action, $entries));
        self::assertSame(['expires_at' => '2026-10-27T19:45:30+00:00', 'purpose' => 'acme.password_reset', 'sent_to_email' => true], $entries[0]->details, 'The log holds no address or token.');
        self::assertEquals($actor, $entries[0]->actor);
        self::assertSame(['purpose' => 'acme.password_reset'], $entries[1]->details);
        self::assertEquals($principal->id, $entries[1]->actor);
        self::assertSame([SecurityLogEntryRecorded::class, OneTimeTokenIssued::class, SecurityLogEntryRecorded::class, OneTimeTokenConsumed::class], $this->events->classes());
    }

    #[DataProvider('implementations')]
    public function testStoresOnlyTheVerifiersHash(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
        [$selector, $verifier] = explode('.', $issued->secret->reveal());

        self::assertSame(hash('sha256', $verifier), $this->stack->tokens->find(new Realm('acme'), $selector, 'acme.test')[1] ?? null);
    }

    #[DataProvider('implementations')]
    public function testAcceptsTokensQuireIssued(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $principal = $this->principal();
        // Made exactly as Quire's UserTokens::issue() does, and stored as its migration would.
        $selector = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->stack->tokens->add(new OneTimeToken($selector, new Realm('acme'), $principal->id, 'quire.password_reset', null, $this->clock->now(), $this->clock->now()->modify('+1 hour')), hash('sha256', $verifier));

        self::assertNotNull($tokens->consume(new Realm('acme'), 'quire.password_reset', Secret::fromString($selector.'.'.$verifier), new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testAWrongVerifierNeitherWorksNorUsesTheToken(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
        $selector = $issued->token->selector;

        foreach ([$selector.'.'.str_repeat('A', 43), $selector.'.', $selector, strtoupper($issued->secret->reveal()), $issued->secret->reveal().'x', ' '.$issued->secret->reveal(), $issued->secret->reveal()."\n", 'x'] as $wrong) {
            self::assertNull($tokens->consume(new Realm('acme'), 'acme.test', Secret::fromString($wrong), new Origin(OriginKind::Web)), \sprintf('"%s" worked.', $wrong));
        }

        self::assertNotNull($tokens->consume(new Realm('acme'), 'acme.test', $issued->secret, new Origin(OriginKind::Web)), 'Wrong guesses used the token up.');
    }

    #[DataProvider('implementations')]
    public function testATokenWorksOnlyInItsRealmAndForItsPurpose(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, 'acme.invitation', Duration::hours(1)), new Origin(OriginKind::Web));

        self::assertNull($tokens->consume(new Realm('globex'), 'acme.invitation', $issued->secret, new Origin(OriginKind::Web)));
        self::assertNull($tokens->consume(new Realm('acme'), 'acme.password_reset', $issued->secret, new Origin(OriginKind::Web)), 'An invitation reset a password.');
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.invitation', $issued->secret));
    }

    #[DataProvider('implementations')]
    public function testATokenExpires(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));

        $this->clock->sleep(3599);
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.test', $issued->secret));
        $this->clock->sleep(1);
        self::assertNull($tokens->consume(new Realm('acme'), 'acme.test', $issued->secret, new Origin(OriginKind::Web)));

        self::assertSame(1, $tokens->purge());
        self::assertNull($this->stack->tokens->find(new Realm('acme'), $issued->token->selector, 'acme.test'));
    }

    #[DataProvider('implementations')]
    public function testOnlyTheNewestWorksUnlessAskedOtherwise(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $principal = $this->principal();
        $first = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
        $second = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
        $alongside = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1), newestOnly: false), new Origin(OriginKind::Web));
        $otherPurpose = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.other', Duration::hours(1)), new Origin(OriginKind::Web));

        self::assertNull($tokens->peek(new Realm('acme'), 'acme.test', $first->secret));
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.test', $second->secret));
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.test', $alongside->secret));

        // Using one stops the principal's others for the purpose.
        self::assertNotNull($tokens->consume(new Realm('acme'), 'acme.test', $alongside->secret, new Origin(OriginKind::Web)));
        self::assertNull($tokens->peek(new Realm('acme'), 'acme.test', $second->secret));
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.other', $otherPurpose->secret));
    }

    #[DataProvider('implementations')]
    public function testATokenSentByEmailWorksOnlyWhileTheAddressIsTheSame(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $principal = $this->principal(email: 'ana@example.com');
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1), 'ana@example.com'), new Origin(OriginKind::Web));
        $unbound = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.other', Duration::hours(1)), new Origin(OriginKind::Web));

        $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $principal->id, IdentifierKind::Email, 'ANA@example.com'), new Origin(OriginKind::Web));
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.test', $issued->secret), 'The same address in other letters is the same address.');

        $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $principal->id, IdentifierKind::Email, 'ana@example.org'), new Origin(OriginKind::Web));
        self::assertNull($tokens->consume(new Realm('acme'), 'acme.test', $issued->secret, new Origin(OriginKind::Web)), 'A link sent to an old address worked.');
        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.other', $unbound->secret));

        $again = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1), 'ana@example.org'), new Origin(OriginKind::Web));
        $this->principals->removeIdentifier(new RemoveIdentifier(new Realm('acme'), $principal->id, IdentifierKind::Email), new Origin(OriginKind::Web));
        self::assertNull($tokens->peek(new Realm('acme'), 'acme.test', $again->secret), 'A link sent to a removed address worked.');
    }

    #[DataProvider('implementations')]
    public function testATokenForASuspendedOrDeletedPrincipalNeverWorks(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $invited = $this->principal(PrincipalStatus::Invited);
        $suspended = $this->principal();
        $deleted = $this->principal();
        $forInvited = $tokens->issue(new IssueToken(new Realm('acme'), $invited->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
        $forSuspended = $tokens->issue(new IssueToken(new Realm('acme'), $suspended->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
        $forDeleted = $tokens->issue(new IssueToken(new Realm('acme'), $deleted->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));

        $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $suspended->id), new Origin(OriginKind::Web));
        $this->principals->delete(new DeletePrincipal(new Realm('acme'), $deleted->id), new Origin(OriginKind::Web));

        self::assertNotNull($tokens->peek(new Realm('acme'), 'acme.test', $forInvited->secret), 'Invited principals get tokens: their invitations.');
        self::assertNull($tokens->consume(new Realm('acme'), 'acme.test', $forSuspended->secret, new Origin(OriginKind::Web)));
        self::assertNull($tokens->consume(new Realm('acme'), 'acme.test', $forDeleted->secret, new Origin(OriginKind::Web)));

        $this->expectException(InactivePrincipal::class);
        $this->expectExceptionMessage(\sprintf('The principal %s is suspended, and can\'t be sent a token.', $suspended->id));

        $tokens->issue(new IssueToken(new Realm('acme'), $suspended->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testRevokesAPrincipalsTokensForAPurpose(string $implementation): void
    {
        $tokens = $this->tokens($implementation);
        $principal = $this->principal();
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));

        self::assertSame(1, $tokens->revoke(new RevokeTokens(new Realm('acme'), $principal->id, 'acme.test'), new Origin(OriginKind::Web)));
        self::assertSame(0, $tokens->revoke(new RevokeTokens(new Realm('acme'), $principal->id, 'acme.test'), new Origin(OriginKind::Web)));
        self::assertNull($tokens->peek(new Realm('acme'), 'acme.test', $issued->secret));

        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertCount(3, $entries, 'Revoking nothing was recorded.');
        self::assertSame(['purpose' => 'acme.test', 'tokens' => 1], $entries[2]->details);
    }

    public function testRefusesAnInvalidPurpose(): void
    {
        $tokens = $this->tokens('memory');

        foreach (['', 'Acme.test', 'acme..test', 'acme.test.', '1acme', 'acme test', str_repeat('a', 65)] as $purpose) {
            try {
                $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, $purpose, Duration::hours(1)), new Origin(OriginKind::Web));
                self::fail(\sprintf('The purpose "%s" was accepted.', $purpose));
            } catch (IntegratorError $error) {
                self::assertStringContainsString('is invalid', $error->getMessage());
            }
        }

        self::assertSame(str_repeat('a', 64), $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, str_repeat('a', 64), Duration::hours(1)), new Origin(OriginKind::Web))->token->purpose);
    }

    public function testATokenIsSentOnlyToItsPrincipalsAddress(): void
    {
        $tokens = $this->tokens('memory');
        $principal = $this->principal(email: 'ana@example.com');

        foreach (['bob@example.com', 'not an address'] as $address) {
            try {
                $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1), $address), new Origin(OriginKind::Web));
                self::fail(\sprintf('A token was sent to "%s".', $address));
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('A token can be sent only to its principal\'s email address.', $error->getMessage());
            }
        }

        $this->expectException(IntegratorError::class);

        $tokens->issue(new IssueToken(new Realm('acme'), $this->principal()->id, 'acme.test', Duration::hours(1), 'ana@example.com'), new Origin(OriginKind::Web));
    }

    public function testRefusesUnknownPrincipals(): void
    {
        $tokens = $this->tokens('memory');

        $this->expectException(UnknownPrincipal::class);

        $tokens->issue(new IssueToken(new Realm('acme'), new PrincipalId(new UuidV7Generator()->next()), 'acme.test', Duration::hours(1)), new Origin(OriginKind::Web));
    }

    public function testTokenCommandsAreOperations(): void
    {
        $catalogue = new OperationCatalogue([IssueToken::class, RevokeTokens::class]);

        self::assertSame('yorvoid.token.revoke', $catalogue->get('yorvoid.token.issue')->inverse);
        self::assertSame('yorvoid.principals.manage', $catalogue->get('yorvoid.token.revoke')->ability);
    }

    private function tokens(string $implementation): OneTimeTokens
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);

        return new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, $this->events, $this->clock);
    }

    private function principal(PrincipalStatus $status = PrincipalStatus::Active, ?string $email = null): Principal
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, $status, $email), new Origin(OriginKind::Console));
        $this->events->dispatched = [];

        return $principal;
    }
}
