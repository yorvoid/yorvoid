<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\RevokeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\ActivatePrincipal;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierTaken;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Principal\VerifyIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Invitations by email (IDN-020, ATN-032): an invited principal, and a one-time link sent to its
 * address. Opening the link and accepting activates the principal and verifies the address; the person
 * then chooses how they sign in (a passkey).
 *
 * The link is a one-time token for the purpose "yorvoid.invitation", bound to the address: changing
 * the invited principal's address makes it stop working.
 *
 * @api
 */
final readonly class Invitations implements OperationHandler
{
    public const string PURPOSE = 'yorvoid.invitation';

    private Limit $emailLimit;

    /**
     * @param Limit|null $emailLimit How often an invitation may be sent again to the same person (ATN-043): once every
     *                               2 minutes if not given, so a resend button can't flood their inbox
     */
    public function __construct(
        private Principals $principals,
        private OneTimeTokens $tokens,
        private Transactions $transactions,
        private Throttle $throttle,
        private Sessions $sessions,
        ?Limit $emailLimit = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
        $this->emailLimit = $emailLimit ?? new Limit(1, Duration::minutes(2));
    }

    public function operations(): array
    {
        return [
            Invite::class => $this->invite(...),
            ResendInvitation::class => $this->resend(...),
            WithdrawInvitation::class => $this->withdraw(...),
            AcceptInvitation::class => $this->accept(...),
        ];
    }

    /**
     * @throws InvalidIdentifier When the email address or username isn't valid
     * @throws IdentifierTaken   When another principal in the realm has it
     */
    public function invite(Invite $command, Permit $permit, Origin $origin): Invitation
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): Invitation {
            $principal = $permit->cause($this->principals->create(...), new CreatePrincipal($command->realm, PrincipalKind::Human, PrincipalStatus::Invited, $command->email, $command->username), $origin);

            return new Invitation($principal, $this->send($principal, $command->email, $command->lifetime, $permit, $origin));
        });
    }

    /**
     * @throws UnknownPrincipal When there's no such principal
     * @throws NotInvited       When it isn't invited
     * @throws Throttled        When it was sent too recently; nothing was sent
     */
    public function resend(ResendInvitation $command, Permit $permit, Origin $origin): Invitation
    {
        $permit->use($command);
        $this->throttle->hit(\sprintf('invitation:%s:%s', $command->realm->name, $command->principalId), $this->emailLimit);

        return $this->transactions->run(function () use ($command, $permit, $origin): Invitation {
            $principal = $this->invited($command->realm, $command->principalId);
            $email = $this->principals->identifiers($principal->realm, $principal->id)[IdentifierKind::Email->value] ?? throw new NotInvited($principal);

            return new Invitation($principal, $this->send($principal, $email->value, $command->lifetime, $permit, $origin));
        });
    }

    /**
     * @throws UnknownPrincipal When there's no such principal
     * @throws NotInvited       When it isn't invited
     */
    public function withdraw(WithdrawInvitation $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);

        $this->transactions->run(function () use ($command, $permit, $origin): void {
            $principal = $this->invited($command->realm, $command->principalId);
            $permit->cause($this->tokens->revoke(...), new RevokeTokens($principal->realm, $principal->id, self::PURPOSE), $origin);
            $permit->cause($this->principals->delete(...), new DeletePrincipal($principal->realm, $principal->id), $origin);
        });
    }

    /**
     * The invited principal, if the link works: for the page that asks them to accept.
     */
    public function peek(Realm $realm, #[\SensitiveParameter] Secret $token): ?Principal
    {
        $invitation = $this->tokens->peek($realm, self::PURPOSE, $token);
        $principal = null === $invitation ? null : $this->principals->get($realm, $invitation->principalId);

        return PrincipalStatus::Invited === $principal?->status ? $principal : null;
    }

    /**
     * Accepts the invitation: the link stops working, the principal becomes active, and its email address
     * verified, since they opened what was sent there, and they're signed in; what follows acts as them.
     * Let them add a passkey in the same request.
     *
     * @return StartedSession|null Their session; null if the link doesn't work
     */
    public function accept(AcceptInvitation $command, Permit $permit, Origin $origin): ?StartedSession
    {
        $permit->use($command);
        $realm = $command->realm;
        $token = $command->token;

        return $this->transactions->run(function () use ($command, $realm, $token, $permit, $origin): ?StartedSession {
            if (null === $this->peek($realm, $token)) {
                return null;
            }

            $used = $this->tokens->consume($realm, self::PURPOSE, $token, $origin);

            if (null === $used) {
                return null;
            }

            // Invitations are always sent to the invitee's address, and work only while it's theirs.
            $email = $used->email;
            \assert(null !== $email);
            $invitee = new ActingPrincipal($realm, $used->principalId);
            $principal = $permit->cause($this->principals->activate(...), new ActivatePrincipal($realm, $used->principalId), $origin, $invitee);
            $permit->cause($this->principals->verifyIdentifier(...), new VerifyIdentifier($realm, $principal->id, IdentifierKind::Email, $email), $origin, $invitee);

            return $permit->cause($this->sessions->start(...), new StartSession($realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::EmailLink, $this->clock->now())), $command->device, $command->address, $command->deviceCookie), $origin, $invitee);
        });
    }

    private function send(Principal $principal, string $email, ?Duration $lifetime, Permit $permit, Origin $origin): IssuedToken
    {
        return $permit->cause($this->tokens->issue(...), new IssueToken($principal->realm, $principal->id, self::PURPOSE, $lifetime ?? Duration::days(7), $email), $origin);
    }

    private function invited(Realm $realm, PrincipalId $id): Principal
    {
        $principal = $this->principals->get($realm, $id) ?? throw new UnknownPrincipal($realm, $id);

        return PrincipalStatus::Invited === $principal->status ? $principal : throw new NotInvited($principal);
    }
}
