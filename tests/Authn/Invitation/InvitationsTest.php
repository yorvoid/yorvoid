<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Invitation;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Invitation\Invitations;
use YorvoID\Authn\Invitation\Invite;
use YorvoID\Authn\Invitation\NotInvited;
use YorvoID\Authn\Invitation\ResendInvitation;
use YorvoID\Authn\Invitation\WithdrawInvitation;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierTaken;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Invitations on every implementation (IDN-020, ATN-032).
 */
final class InvitationsTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private Principals $principals;

    #[DataProvider('implementations')]
    public function testAnInviteeAcceptsWithTheLinkSentToThem(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $admin = new PrincipalId(new UuidV7Generator()->next());

        $invitation = $invitations->invite(new Invite(new Realm('acme'), 'Ana@Example.com', 'ana'), new Origin(OriginKind::Web), $admin);

        self::assertSame(PrincipalStatus::Invited, $invitation->principal->status);
        self::assertSame(Invitations::PURPOSE, $invitation->token->token->purpose);
        self::assertSame('ana@example.com', $invitation->token->token->email, 'The link is bound to the address it\'s sent to.');
        self::assertEquals(new \DateTimeImmutable('2026-11-03 18:45:30 UTC'), $invitation->token->token->expiresAt, 'Seven days by default.');
        self::assertFalse($this->principals->identifiers(new Realm('acme'), $invitation->principal->id)['email']->isVerified());
        self::assertEquals($invitation->principal, $invitations->peek(new Realm('acme'), $invitation->token->secret));

        $this->clock->sleep(60);
        $accepted = $invitations->accept(new Realm('acme'), $invitation->token->secret, new Origin(OriginKind::Web));

        self::assertSame(PrincipalStatus::Active, $accepted?->status);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:46:30 UTC'), $this->principals->identifiers(new Realm('acme'), $invitation->principal->id)['email']->verifiedAt, 'Opening what was sent to the address shows it\'s theirs.');
        self::assertNull($invitations->accept(new Realm('acme'), $invitation->token->secret, new Origin(OriginKind::Web)), 'An invitation was accepted twice.');
        self::assertNull($invitations->peek(new Realm('acme'), $invitation->token->secret));

        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertSame(['yorvoid.principal.create', 'yorvoid.token.issue', 'yorvoid.token.consume', 'yorvoid.principal.activate', 'yorvoid.identifier.verify'], array_map(static fn ($entry): string => $entry->action, $entries));
        self::assertEquals($admin, $entries[0]->actor);
        self::assertEquals($invitation->principal->id, $entries[3]->actor, 'The invitee activated themselves.');
    }

    #[DataProvider('implementations')]
    public function testResendingReplacesTheLink(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $first = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com', lifetime: Duration::days(2)), new Origin(OriginKind::Web));

        $second = $invitations->resend(new ResendInvitation(new Realm('acme'), $first->principal->id, Duration::days(3)), new Origin(OriginKind::Web));

        self::assertEquals(new \DateTimeImmutable('2026-10-30 18:45:30 UTC'), $second->token->token->expiresAt);
        self::assertNull($invitations->peek(new Realm('acme'), $first->token->secret), 'The earlier link still works.');
        self::assertNotNull($invitations->accept(new Realm('acme'), $second->token->secret, new Origin(OriginKind::Web)));

        $this->clock->sleep(120);
        $this->expectException(NotInvited::class);
        $this->expectExceptionMessage(\sprintf('The principal %s is active, not invited.', $first->principal->id));

        $invitations->resend(new ResendInvitation(new Realm('acme'), $first->principal->id), new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testAnInvitationIsSentAgainAtMostEveryTwoMinutes(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $ana = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Web));
        $ben = $invitations->invite(new Invite(new Realm('acme'), 'ben@example.com'), new Origin(OriginKind::Web));
        $invitations->resend(new ResendInvitation(new Realm('acme'), $ana->principal->id), new Origin(OriginKind::Web));
        $this->clock->sleep(119);

        try {
            $invitations->resend(new ResendInvitation(new Realm('acme'), $ana->principal->id), new Origin(OriginKind::Web));
            self::fail('An invitation was sent again within 2 minutes.');
        } catch (Throttled $exception) {
            self::assertSame(1, $exception->seconds);
        }

        $invitations->resend(new ResendInvitation(new Realm('acme'), $ben->principal->id), new Origin(OriginKind::Web));
        $this->clock->sleep(1);
        $invitations->resend(new ResendInvitation(new Realm('acme'), $ana->principal->id), new Origin(OriginKind::Web));
    }

    public function testTheEmailLimitIsConfigurable(): void
    {
        $this->invitations('memory');
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock);
        $invitations = new Invitations($this->principals, new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, clock: $this->clock), $this->stack->transactions, new Throttle($this->stack->throttle, $this->clock), new Limit(2, Duration::minutes(10)));
        $ana = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Web));

        $invitations->resend(new ResendInvitation(new Realm('acme'), $ana->principal->id), new Origin(OriginKind::Web));
        $invitations->resend(new ResendInvitation(new Realm('acme'), $ana->principal->id), new Origin(OriginKind::Web));

        $this->expectException(Throttled::class);

        $invitations->resend(new ResendInvitation(new Realm('acme'), $ana->principal->id), new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testWithdrawingDeletesTheInviteeAndTheLink(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $invitation = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Web));

        $invitations->withdraw(new WithdrawInvitation(new Realm('acme'), $invitation->principal->id), new Origin(OriginKind::Web));

        self::assertSame(PrincipalStatus::Deleted, $this->principals->get(new Realm('acme'), $invitation->principal->id)?->status);
        self::assertNull($invitations->accept(new Realm('acme'), $invitation->token->secret, new Origin(OriginKind::Web)));
        self::assertNull($this->stack->tokens->find(new Realm('acme'), $invitation->token->token->selector, Invitations::PURPOSE), 'The withdrawn link was kept.');
        $again = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Web));
        self::assertFalse($again->principal->id->equals($invitation->principal->id), 'The address can be invited again, as someone new.');
    }

    #[DataProvider('implementations')]
    public function testTheLinkStopsWorkingWhenTheAddressChanges(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $invitation = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Web));

        $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $invitation->principal->id, IdentifierKind::Email, 'ana@example.org'), new Origin(OriginKind::Web));

        self::assertNull($invitations->accept(new Realm('acme'), $invitation->token->secret, new Origin(OriginKind::Web)));
        self::assertSame(PrincipalStatus::Invited, $this->principals->get(new Realm('acme'), $invitation->principal->id)?->status);
    }

    #[DataProvider('implementations')]
    public function testOnlyAnInvitedPrincipalCanAccept(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $invitation = $invitations->invite(new Invite(new Realm('acme'), 'ana@example.com'), new Origin(OriginKind::Web));
        $this->principals->activate(new \YorvoID\Core\Principal\ActivatePrincipal(new Realm('acme'), $invitation->principal->id), new Origin(OriginKind::Console));

        self::assertNull($invitations->peek(new Realm('acme'), $invitation->token->secret));
        self::assertNull($invitations->accept(new Realm('acme'), $invitation->token->secret, new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testInvitingATakenAddressInvitesNobody(string $implementation): void
    {
        $invitations = $this->invitations($implementation);
        $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Console));

        try {
            $invitations->invite(new Invite(new Realm('acme'), 'ANA@example.com'), new Origin(OriginKind::Web));
            self::fail('The address was invited twice.');
        } catch (IdentifierTaken) {
        }

        self::assertCount(1, iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false));
    }

    public function testRefusesUnknownAndUninvitedPrincipals(): void
    {
        $invitations = $this->invitations('memory');
        $active = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Console));

        try {
            $invitations->withdraw(new WithdrawInvitation(new Realm('acme'), $active->id), new Origin(OriginKind::Web));
            self::fail('An active principal\'s invitation was withdrawn.');
        } catch (NotInvited) {
        }

        self::assertSame(PrincipalStatus::Active, $this->principals->get(new Realm('acme'), $active->id)?->status);

        $this->expectException(UnknownPrincipal::class);

        $invitations->resend(new ResendInvitation(new Realm('acme'), new PrincipalId(new UuidV7Generator()->next())), new Origin(OriginKind::Web));
    }

    public function testAnInvitedPrincipalWithoutAnAddressCantBeSentALink(): void
    {
        $invitations = $this->invitations('memory');
        $invited = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Invited), new Origin(OriginKind::Console));

        $this->expectException(NotInvited::class);

        $invitations->resend(new ResendInvitation(new Realm('acme'), $invited->id), new Origin(OriginKind::Web));
    }

    public function testInvitationCommandsAreOperations(): void
    {
        $catalogue = new OperationCatalogue([Invite::class, ResendInvitation::class, WithdrawInvitation::class]);

        self::assertSame('yorvoid.invitation.withdraw', $catalogue->get('yorvoid.invitation.send')->inverse);
        self::assertSame('yorvoid.principals.manage', $catalogue->get('yorvoid.invitation.resend')->ability);
    }

    private function invitations(string $implementation): Invitations
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, clock: $this->clock);

        return new Invitations($this->principals, new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, clock: $this->clock), $this->stack->transactions, new Throttle($this->stack->throttle, $this->clock));
    }
}
