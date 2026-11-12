<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Token;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Invitation\Invite;
use YorvoID\Authn\Invitation\ResendInvitation;
use YorvoID\Authn\Invitation\WithdrawInvitation;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\RevokeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\Event\PrincipalCreating;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Notifications\IssueNotMeLink;
use YorvoID\Notifications\SecurityNotifications;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\RecordingLogger;
use YorvoID\Tests\Support\Stack;

/**
 * M2-13 fix 4 (H-3, L-2): YorvoID's own token purposes belong to their operations, a token works for its
 * own purpose only, and a consequence is about its operation's principal, once. With the audit's
 * DP-01, DP-01b and DP-02 rebuilt from its reports.
 */
final class ReservedPurposesTest extends DatabaseTestCase
{
    /** Which commands' operations own each purpose */
    private const array OWNED_BY = [
        'yorvoid.recovery_link' => [IssueRecoveryLink::class],
        'yorvoid.invitation' => [ResendInvitation::class, Invite::class],
        'yorvoid.email_verification' => [SetIdentifier::class, CreatePrincipal::class],
        'yorvoid.not_me' => [IssueNotMeLink::class],
    ];

    private Realm $realm;

    private Stack $stack;

    private RecordingDispatcher $events;

    private Principals $principals;

    private OneTimeTokens $tokens;

    private Principal $ana;

    private Principal $admin;

    private CredentialId $credential;

    private RecordingLogger $logger;

    /**
     * The architect's matrix: a token issued for one purpose works for it, and for no other.
     */
    #[DataProvider('implementations')]
    public function testATokenWorksForItsOwnPurposeOnly(string $implementation): void
    {
        $this->world($implementation);
        $issued = [];

        foreach ($this->owners() as $purpose => $owner) {
            $issued[$purpose] = $this->issue($purpose, $owner);
        }

        foreach ($issued as $purpose => $token) {
            foreach (array_keys($issued) as $other) {
                if ($other !== $purpose) {
                    self::assertNull($this->tokens->peek($this->realm, $other, $token->secret), $purpose.' as '.$other);
                    self::assertNull($this->tokens->consume($this->realm, $other, $token->secret, new Origin(OriginKind::Web)), $purpose.' as '.$other);
                }
            }

            self::assertEquals($token->token, $this->tokens->peek($this->realm, $purpose, $token->secret), $purpose);
            self::assertEquals($token->token, $this->tokens->consume($this->realm, $purpose, $token->secret, new Origin(OriginKind::Web)), $purpose);
        }
    }

    /**
     * Only the owner issues a token for YorvoID's own purpose: not at the top level, the operator's
     * included, nor as a consequence of any other operation that causes tokens.
     */
    public function testOnlyTheOwnerIssues(): void
    {
        $this->world('memory');
        $owners = $this->owners();
        unset($owners['acme.password_reset']);
        $parents = array_values(array_filter($owners));
        $parents[] = new Invite($this->realm, 'new@example.com');
        $parents[] = new CreatePrincipal($this->realm, PrincipalKind::Human);

        foreach ([...array_keys($owners), 'yorvoid', 'yorvoid.other', 'yorvoid.recovery_link.x'] as $purpose) {
            $key = str_starts_with($purpose, 'yorvoid.not_me.') ? 'yorvoid.not_me' : $purpose;
            $command = new IssueToken($this->realm, $this->ana->id, $purpose, Duration::hours(1));

            foreach ([$this->ana->id, new Operator()] as $actor) {
                $this->assertRefused(fn () => Permits::call($this->tokens->issue(...), $command, new Origin(OriginKind::Web), $actor), 'issue', $purpose);
            }

            foreach ($parents as $parent) {
                $owns = \in_array($parent::class, self::OWNED_BY[$key] ?? [], true);
                $run = fn (): IssuedToken => $this->tokens->issue($command, $this->because($parent)->consequence($command), new Origin(OriginKind::Web));

                if ($owns) {
                    self::assertSame($purpose, $run()->token->purpose, $purpose.' by '.$parent::class);
                } else {
                    $this->assertRefused($run, 'issue', $purpose);
                }
            }
        }

        self::assertSame('The purpose "yorvoid.recovery_link" is YorvoID\'s own: only "yorvoid.recovery_link.issue" issues tokens for it.', $this->refusal(fn () => Permits::call($this->tokens->issue(...), new IssueToken($this->realm, $this->ana->id, 'yorvoid.recovery_link', Duration::hours(1)), new Origin(OriginKind::Web))));
        self::assertSame('The purpose "yorvoid.other" is YorvoID\'s own: nothing issues tokens for it.', $this->refusal(fn () => Permits::call($this->tokens->issue(...), new IssueToken($this->realm, $this->ana->id, 'yorvoid.other', Duration::hours(1)), new Origin(OriginKind::Web))));
    }

    public function testOnlyWithdrawingAnInvitationRevokesItsTokens(): void
    {
        $this->world('memory');

        foreach (['yorvoid.invitation', 'yorvoid.recovery_link', 'yorvoid.email_verification', 'yorvoid.not_me', SecurityNotifications::notMePurpose(CredentialId::fromString((string) new UuidV7()))] as $purpose) {
            $command = new RevokeTokens($this->realm, $this->ana->id, $purpose);
            $this->assertRefused(fn () => Permits::call($this->tokens->revoke(...), $command, new Origin(OriginKind::Web), $this->ana->id), 'revoke', $purpose);
            $run = fn (): int => $this->tokens->revoke($command, $this->because(new WithdrawInvitation($this->realm, $this->ana->id))->consequence($command), new Origin(OriginKind::Web));

            if ('yorvoid.invitation' === $purpose) {
                self::assertSame(0, $run());
            } else {
                $this->assertRefused($run, 'revoke', $purpose);
            }
        }

        self::assertSame(0, Permits::call($this->tokens->revoke(...), new RevokeTokens($this->realm, $this->ana->id, 'acme.password_reset'), new Origin(OriginKind::Web), $this->ana->id), 'An application\'s own.');
        self::assertSame('The purpose "yorvoid.not_me.cx" is YorvoID\'s own: nothing revokes tokens for it.', $this->refusal(fn () => Permits::call($this->tokens->revoke(...), new RevokeTokens($this->realm, $this->ana->id, 'yorvoid.not_me.cx'), new Origin(OriginKind::Web))));
    }

    /**
     * DP-01: a helpdesk role with principals.manage mints a year-long recovery link for a super admin.
     * DP-01b: or a "this wasn't me" link for their passkey.
     */
    public function testAPrincipalsManagerMintsNoRecoveryOrNotMeLink(): void
    {
        $this->world('memory');
        $helpdesk = $this->principal('help@example.com')->id;

        foreach (['yorvoid.recovery_link', SecurityNotifications::notMePurpose($this->credential), 'yorvoid.not_me'] as $purpose) {
            $this->assertRefused(fn () => Permits::call($this->tokens->issue(...), new IssueToken($this->realm, $this->admin->id, $purpose, Duration::days(365)), new Origin(OriginKind::Web), $helpdesk), 'issue', $purpose);
        }

        $denied = $this->events->of(SecurityLogEntryRecorded::class);
        $last = end($denied);
        self::assertNotFalse($last);
        self::assertSame(['yorvoid.token.issue', Outcome::Denied, (string) $helpdesk, (string) $this->admin->id], [$last->entry->action, $last->entry->outcome, (string) $last->entry->actor, $last->entry->subjectId]);
        self::assertSame(['purpose' => 'yorvoid.not_me', 'reason' => 'The purpose "yorvoid.not_me" is YorvoID\'s own: only "yorvoid.not_me.issue" issues tokens for it.'], $last->entry->details);
    }

    /**
     * DP-02: a listener handed an address change's permit issued three recovery links for an admin.
     */
    public function testAnEventsPermitRunsOnlyItsOwnConsequenceOnce(): void
    {
        $this->world('memory');
        $outcomes = [];
        $this->events->listen(IdentifierChanged::class, function (IdentifierChanged $event) use (&$outcomes): void {
            foreach ([[$this->admin->id, 'yorvoid.recovery_link'], [$this->admin->id, 'yorvoid.email_verification'], [$this->ana->id, 'yorvoid.email_verification'], [$this->ana->id, 'yorvoid.email_verification'], [$this->ana->id, 'yorvoid.recovery_link']] as [$whom, $purpose]) {
                $command = new IssueToken($this->realm, $whom, $purpose, Duration::days(1));

                try {
                    $this->tokens->issue($command, $event->permit->consequence($command), $event->origin);
                    $outcomes[] = 'issued';
                } catch (IntegratorError|NotPermitted $refused) {
                    $outcomes[] = strtok($refused->getMessage(), "\n");
                }
            }
        });

        Permits::call($this->principals->setIdentifier(...), new SetIdentifier($this->realm, $this->ana->id, IdentifierKind::Email, 'ana.new@example.com'), new Origin(OriginKind::Web), $this->ana->id);

        self::assertSame([
            'A consequence of "yorvoid.identifier.set" is about another principal.',
            'A consequence of "yorvoid.identifier.set" is about another principal.',
            'issued',
            'This permit for "yorvoid.identifier.set" ran its consequence "yorvoid.token.issue" already: as many times as it says it causes it.',
            'This permit for "yorvoid.identifier.set" ran its consequence "yorvoid.token.issue" already: as many times as it says it causes it.',
        ], $outcomes);
        // And a recovery link isn't the address change's to issue, once or ever.
        $command = new IssueToken($this->realm, $this->ana->id, 'yorvoid.recovery_link', Duration::days(1));
        $this->assertRefused(fn (): IssuedToken => $this->tokens->issue($command, $this->because(new SetIdentifier($this->realm, $this->ana->id, IdentifierKind::Email, 'ana@example.com'))->consequence($command), new Origin(OriginKind::Web)), 'issue', 'yorvoid.recovery_link');
    }

    /**
     * C1 (a): creating a principal is about it from the start, so a listener of PrincipalCreating can't
     * point the permit at anyone else.
     */
    public function testCreatingAPrincipalIsAboutItBeforeAnyoneHearsOfIt(): void
    {
        $this->world('memory');
        $outcomes = [];
        $this->events->listen(PrincipalCreating::class, function (PrincipalCreating $event) use (&$outcomes): void {
            foreach ([$this->ana->id, $event->principal->id] as $whom) {
                $command = new IssueToken($this->realm, $whom, 'yorvoid.email_verification', Duration::days(1));

                try {
                    $event->permit->consequence($command);
                    $outcomes[] = 'permitted';
                } catch (IntegratorError $refused) {
                    $outcomes[] = strtok($refused->getMessage(), "\n");
                }
            }
        });

        $this->principal('new@example.com');

        self::assertSame(['A consequence of "yorvoid.principal.create" is about another principal.', 'permitted'], $outcomes);
    }

    /**
     * N1: a refusal inside another operation's transaction leaves its warning, though the rollback takes the
     * security log's record with it.
     */
    #[DataProvider('implementations')]
    public function testARefusalInsideARolledBackOperationLeavesItsWarning(string $implementation): void
    {
        $this->world($implementation);
        $parent = $this->because(new SetIdentifier($this->realm, $this->ana->id, IdentifierKind::Email, 'ana@example.com'));
        $command = new IssueToken($this->realm, $this->ana->id, 'yorvoid.recovery_link', Duration::days(1));
        $before = \count(iterator_to_array($this->stack->log->entries($this->realm, 1), false));

        try {
            $this->stack->transactions->run(fn (): IssuedToken => $this->tokens->issue($command, $parent->consequence($command), new Origin(OriginKind::Web)));
            self::fail('Not refused.');
        } catch (NotPermitted) {
        }

        self::assertSame([[
            'warning',
            'YorvoID refused to {what} a token for its own purpose {purpose}: {reason}',
            [
                'what' => 'issue',
                'purpose' => 'yorvoid.recovery_link',
                'consequence_of' => 'yorvoid.identifier.set',
                'decision' => 'test',
                'realm' => 'acme',
                'principal' => (string) $this->ana->id,
                'reason' => 'The purpose "yorvoid.recovery_link" is YorvoID\'s own: only "yorvoid.recovery_link.issue" issues tokens for it.',
            ],
        ]], $this->logger->logs, 'The operator\'s permit: no actor.');

        if ('memory' !== $implementation) {
            self::assertCount($before, iterator_to_array($this->stack->log->entries($this->realm, 1), false), 'The rollback took the record.');
        }

        // A principal's refusal at the top level names them.
        $this->assertRefused(fn () => Permits::call($this->tokens->issue(...), $command, new Origin(OriginKind::Web), $this->admin->id), 'issue', 'yorvoid.recovery_link');
        self::assertSame((string) $this->admin->id, $this->logger->logs[1][2]['actor'] ?? null);
        self::assertArrayNotHasKey('consequence_of', $this->logger->logs[1][2]);
    }

    private function world(string $implementation): void
    {
        $this->realm = new Realm('acme');
        $this->stack = $this->stack($implementation);
        $clock = new MockClock('2026-11-12 18:00:00 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, $this->events, ids: $ids, clock: $clock);
        $this->logger = new RecordingLogger();
        $this->tokens = new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, clock: $clock, logger: $this->logger);
        $this->ana = $this->principal('ana@example.com');
        $this->admin = $this->principal('admin@example.com');
        $this->credential = CredentialId::fromString((string) new UuidV7());
    }

    private function principal(string $email): Principal
    {
        return Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, email: $email), new Origin(OriginKind::Console));
    }

    /**
     * Each purpose and the command of the operation that owns it, Ana's; an application's own needs none.
     *
     * @return array<string, Command<mixed>|null>
     */
    private function owners(): array
    {
        return [
            'yorvoid.recovery_link' => new IssueRecoveryLink($this->realm, $this->ana->id),
            'yorvoid.invitation' => new ResendInvitation($this->realm, $this->ana->id),
            'yorvoid.email_verification' => new SetIdentifier($this->realm, $this->ana->id, IdentifierKind::Email, 'ana@example.com'),
            'yorvoid.not_me' => new IssueNotMeLink($this->realm, $this->ana->id, null, Duration::days(1)),
            SecurityNotifications::notMePurpose($this->credential) => new IssueNotMeLink($this->realm, $this->ana->id, $this->credential, Duration::days(1)),
            'acme.password_reset' => null,
        ];
    }

    /**
     * @param Command<mixed>|null $owner
     */
    private function issue(string $purpose, ?Command $owner): IssuedToken
    {
        $command = new IssueToken($this->realm, $this->ana->id, $purpose, Duration::hours(1), newestOnly: false);

        return null === $owner
            ? Permits::call($this->tokens->issue(...), $command, new Origin(OriginKind::Web), $this->ana->id)
            : $this->tokens->issue($command, $this->because($owner)->consequence($command), new Origin(OriginKind::Web));
    }

    /**
     * The owner's permit, used, about Ana.
     *
     * @param Command<mixed> $command
     */
    private function because(Command $command): Permit
    {
        $permit = Permits::for($command);
        $permit->use($command);
        $permit->about($this->ana->id);

        return $permit;
    }

    private function assertRefused(\Closure $run, string $what, string $purpose): void
    {
        try {
            $run();
            self::fail(\sprintf('%s of "%s" wasn\'t refused.', $what, $purpose));
        } catch (NotPermitted $refused) {
            self::assertSame('yorvoid.token.'.$what, $refused->operation);
            self::assertStringStartsWith(\sprintf('The purpose "%s" is YorvoID\'s own: ', $purpose), $refused->decision->reason);
        }
    }

    private function refusal(\Closure $run): string
    {
        try {
            $run();
        } catch (NotPermitted $refused) {
            return $refused->decision->reason;
        }

        self::fail('Not refused.');
    }
}
