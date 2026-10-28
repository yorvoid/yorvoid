<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\RevokeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
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
final readonly class Invitations
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
        ?Limit $emailLimit = null,
    ) {
        $this->emailLimit = $emailLimit ?? new Limit(1, Duration::minutes(2));
    }

    /**
     * @throws InvalidIdentifier When the email address or username isn't valid
     * @throws IdentifierTaken   When another principal in the realm has it
     */
    public function invite(Invite $command, Origin $origin, ?PrincipalId $actor = null): Invitation
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): Invitation {
            $principal = $this->principals->create(new CreatePrincipal($command->realm, PrincipalKind::Human, PrincipalStatus::Invited, $command->email, $command->username), $origin, $actor);

            return new Invitation($principal, $this->send($principal, $command->email, $command->lifetime, $origin, $actor));
        });
    }

    /**
     * @throws UnknownPrincipal When there's no such principal
     * @throws NotInvited       When it isn't invited
     * @throws Throttled        When it was sent too recently; nothing was sent
     */
    public function resend(ResendInvitation $command, Origin $origin, ?PrincipalId $actor = null): Invitation
    {
        $this->throttle->hit(\sprintf('invitation:%s:%s', $command->realm->name, $command->principalId), $this->emailLimit);

        return $this->transactions->run(function () use ($command, $origin, $actor): Invitation {
            $principal = $this->invited($command->realm, $command->principalId);
            $email = $this->principals->identifiers($principal->realm, $principal->id)[IdentifierKind::Email->value] ?? throw new NotInvited($principal);

            return new Invitation($principal, $this->send($principal, $email->value, $command->lifetime, $origin, $actor));
        });
    }

    /**
     * @throws UnknownPrincipal When there's no such principal
     * @throws NotInvited       When it isn't invited
     */
    public function withdraw(WithdrawInvitation $command, Origin $origin, ?PrincipalId $actor = null): void
    {
        $this->transactions->run(function () use ($command, $origin, $actor): void {
            $principal = $this->invited($command->realm, $command->principalId);
            $this->tokens->revoke(new RevokeTokens($principal->realm, $principal->id, self::PURPOSE), $origin, $actor);
            $this->principals->delete(new DeletePrincipal($principal->realm, $principal->id), $origin, $actor);
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
     * verified, since they opened what was sent there. Start their session and let them add a passkey in
     * the same request.
     *
     * @return Principal|null The principal, active; null if the link doesn't work
     */
    public function accept(Realm $realm, #[\SensitiveParameter] Secret $token, Origin $origin): ?Principal
    {
        return $this->transactions->run(function () use ($realm, $token, $origin): ?Principal {
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
            $principal = $this->principals->activate(new ActivatePrincipal($realm, $used->principalId), $origin, $used->principalId);
            $this->principals->verifyIdentifier(new VerifyIdentifier($realm, $principal->id, IdentifierKind::Email, $email), $origin, $used->principalId);

            return $principal;
        });
    }

    private function send(Principal $principal, string $email, ?Duration $lifetime, Origin $origin, ?PrincipalId $actor): IssuedToken
    {
        return $this->tokens->issue(new IssueToken($principal->realm, $principal->id, self::PURPOSE, $lifetime ?? Duration::days(7), $email), $origin, $actor);
    }

    private function invited(Realm $realm, PrincipalId $id): Principal
    {
        $principal = $this->principals->get($realm, $id) ?? throw new UnknownPrincipal($realm, $id);

        return PrincipalStatus::Invited === $principal->status ? $principal : throw new NotInvited($principal);
    }
}
