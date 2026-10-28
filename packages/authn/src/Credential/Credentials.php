<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Credential\Event\CredentialAdded;
use YorvoID\Authn\Credential\Event\CredentialRemoved;
use YorvoID\Authn\Credential\Event\CredentialRemoving;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\HeldAfterRecovery;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Text;
use YorvoID\Core\Time;

/**
 * The credential registry (IDN-013, ATN-005, ATN-027, SES-006): every way a principal signs in or
 * recovers, whichever package keeps its secret part.
 *
 * Changes are made by the signed-in principal, from a session in which they proved who they are within
 * the re-authentication window (5 minutes by default), by signing in or by stepping up for the change: a
 * session taken over earlier can't add the attacker's passkey or remove the owner's. Removing waits out
 * the cool-down after an account recovery, if one is configured. The last credential that signs in
 * can't be removed.
 *
 * @api
 */
final readonly class Credentials
{
    private Duration $reauthenticationWindow;

    public function __construct(
        private CredentialStore $store,
        private Sessions $sessions,
        private StepUp $stepUp,
        private SecurityLog $log,
        private Transactions $transactions,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private IdGenerator $ids = new UuidV7Generator(),
        private ClockInterface $clock = new NativeClock(),
        ?Duration $reauthenticationWindow = null,
    ) {
        $this->reauthenticationWindow = $reauthenticationWindow ?? Duration::minutes(5);
    }

    /**
     * Requires the session to have proved who it is within the re-authentication window, for the operation
     * on a credential: for the packages that keep credentials, e.g. before a passkey ceremony begins.
     *
     * @param string $operation         E.g. "yorvoid.credential.add"
     * @param bool   $heldAfterRecovery Whether the operation waits out a recovery's cool-down
     *
     * @throws StepUpRequired    When the session is ended, or its principal didn't prove who they are recently
     * @throws HeldAfterRecovery
     */
    public function requireStepUp(Session $session, string $operation, bool $heldAfterRecovery = false): void
    {
        $this->stepUp->require($session, new AuthenticationRequirement($this->reauthenticationWindow, operation: $operation, heldAfterRecovery: $heldAfterRecovery));
    }

    /**
     * Adds a credential to the session's principal. The package keeping the credential's secret part
     * stores that in the same transaction.
     *
     * @throws StepUpRequired
     */
    public function add(AddCredential $command, Session $session, Origin $origin): Credential
    {
        if (!$command->realm->equals($session->realm)) {
            throw new IntegratorError('The session is in another realm than the credential.', 'Add credentials in the realm of the signed-in session.', 'concepts/credentials.md#changing-how-someone-signs-in');
        }

        return $this->transactions->run(function () use ($command, $session, $origin): Credential {
            $this->requireStepUp($session, 'yorvoid.credential.add');
            $credential = new Credential(new CredentialId($this->ids->next()), $command->realm, $session->principalId, $command->kind, self::label($command->label, $command->kind), Time::stored($this->clock->now()));
            $this->store->add($credential);
            $this->record('yorvoid.credential.add', $credential, $origin);

            $this->transactions->afterCommit(function () use ($credential, $origin): void {
                $this->events->dispatch(new CredentialAdded($credential, $origin));
            });

            return $credential;
        });
    }

    /**
     * @throws UnknownCredential When the session's principal has no such credential
     * @throws StepUpRequired
     * @throws HeldAfterRecovery When the account was recovered recently
     * @throws LastCredential    When it's the principal's last way to sign in
     */
    public function remove(RemoveCredential $command, Session $session, Origin $origin): void
    {
        $this->transactions->run(function () use ($command, $session, $origin): void {
            $credential = $this->own($command->realm, $command->credentialId, $session);
            $this->requireStepUp($session, 'yorvoid.credential.remove', heldAfterRecovery: true);

            $this->events->dispatch(new CredentialRemoving($credential, $origin));
            $this->store->remove($credential->realm, $credential->id);
            // Recording takes the realm's log lock: two sessions removing the last two passkeys at once
            // count what's left one after the other.
            $this->record('yorvoid.credential.remove', $credential, $origin);

            if ($credential->kind->signsIn() && [] === array_filter($this->store->of($credential->realm, $credential->principalId), static fn (Credential $other): bool => $other->isActive() && $other->kind->signsIn())) {
                throw new LastCredential($credential);
            }

            if ($command->endOtherSessions) {
                $this->sessions->endAll(new EndSessions($credential->realm, $credential->principalId, SessionEndReason::CredentialChanged, except: $session->id), $origin, $credential->principalId);
            }

            $this->transactions->afterCommit(function () use ($credential, $origin): void {
                $this->events->dispatch(new CredentialRemoved($credential, $origin));
            });
        });
    }

    /**
     * @throws UnknownCredential When the session's principal has no such credential
     */
    public function rename(RenameCredential $command, Session $session, Origin $origin): Credential
    {
        return $this->transactions->run(function () use ($command, $session, $origin): Credential {
            $credential = $this->own($command->realm, $command->credentialId, $session);
            $renamed = new Credential($credential->id, $credential->realm, $credential->principalId, $credential->kind, self::label($command->label, $credential->kind), $credential->createdAt, $credential->lastUsedAt, $credential->status);
            $this->store->update($renamed);
            $this->record('yorvoid.credential.rename', $renamed, $origin);

            return $renamed;
        });
    }

    /**
     * Records that the credential was just used to sign in. For the packages that check credentials.
     */
    public function markUsed(Credential $credential): Credential
    {
        $used = new Credential($credential->id, $credential->realm, $credential->principalId, $credential->kind, $credential->label, $credential->createdAt, Time::stored($this->clock->now()), $credential->status);
        $this->store->update($used);

        return $used;
    }

    /**
     * Makes a credential stop working, e.g. a passkey suspected of having been copied. Its owner sees
     * it as disabled, and removes it.
     *
     * @param string $reason Why, for the log, e.g. "signature counter went backwards"
     */
    public function disable(Credential $credential, string $reason, Origin $origin): Credential
    {
        return $this->transactions->run(function () use ($credential, $reason, $origin): Credential {
            $disabled = new Credential($credential->id, $credential->realm, $credential->principalId, $credential->kind, $credential->label, $credential->createdAt, $credential->lastUsedAt, CredentialStatus::Disabled);
            $this->store->update($disabled);
            $this->log->record($credential->realm, 'yorvoid.credential.disable', Outcome::Success, $origin, null, 'credential', (string) $credential->id, [
                'kind' => $credential->kind->value,
                'principal' => (string) $credential->principalId,
                'reason' => $reason,
            ]);

            return $disabled;
        });
    }

    public function get(Realm $realm, CredentialId $id): ?Credential
    {
        return $this->store->get($realm, $id);
    }

    /**
     * A principal's credentials, the oldest first: for "your sign-in methods".
     *
     * @return list<Credential>
     */
    public function of(Realm $realm, PrincipalId $principalId): array
    {
        return $this->store->of($realm, $principalId);
    }

    private function own(Realm $realm, CredentialId $id, Session $session): Credential
    {
        $credential = $this->store->get($realm, $id);

        return null !== $credential && $credential->principalId->equals($session->principalId) && $session->realm->equals($realm) ? $credential : throw new UnknownCredential($realm, $id);
    }

    private function record(string $action, Credential $credential, Origin $origin): void
    {
        $this->log->record($credential->realm, $action, Outcome::Success, $origin, $credential->principalId, 'credential', (string) $credential->id, [
            'kind' => $credential->kind->value,
            'principal' => (string) $credential->principalId,
        ]);
    }

    /**
     * What the owner called it, kept short and valid; the kind's name if they called it nothing.
     */
    private static function label(string $label, CredentialKind $kind): string
    {
        $label = trim(mb_substr(Text::utf8($label), 0, 64));

        return '' === $label ? match ($kind) {
            CredentialKind::Passkey => 'Passkey',
            CredentialKind::Password => 'Password',
            CredentialKind::Totp => 'Authenticator app',
            CredentialKind::RecoveryCodes => 'Recovery codes',
        } : $label;
    }
}
