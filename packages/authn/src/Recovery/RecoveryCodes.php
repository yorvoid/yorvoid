<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\AddCredential;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\HumanCode;
use YorvoID\Authn\Recovery\Event\RecoveryCodesGenerated;
use YorvoID\Authn\Recovery\Event\RecoveryCodeUsed;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\HeldAfterRecovery;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;
use YorvoID\Throttle\Throttled;

/**
 * Recovery codes (ATN-025, ATN-026, ATN-029): codes a person saves when they set up their account, each
 * of which gets them back in once if they lose their passkeys.
 *
 * - 10 by default, each 120 random bits, written as 24 characters of Crockford's base 32 in groups of
 *   four ("7KQ2-M9XH-…"); only their SHA-256 hashes are kept, which is enough above 112 bits (ASVS
 *   6.5.2), and finds a code with one lookup. Case, hyphens and spaces don't matter.
 * - Generating new ones replaces the old set. The first set needs a recent proof of who the person is,
 *   as adding any credential does; a new set needs one too, and waits out a recovery's cool-down.
 * - Using one ends all of the principal's sessions, since whoever else had one may be why they lost
 *   access, starts the cool-down, and starts a new session. Each use is recorded and announced with
 *   RecoveryCodeUsed, for a notification.
 * - Attempts are counted per account and address, per account and per address (see Attempts).
 * - recover() finds the account from what the person typed, and answers, counts and works the same
 *   whether there's no such account, it has no codes, or the code is wrong.
 *
 * @api
 */
final readonly class RecoveryCodes
{
    public const string GENERATE = 'yorvoid.recovery_codes.generate';

    public const string USE = 'yorvoid.recovery_code.use';

    private const int LENGTH = 24;

    /**
     * @param int $count How many codes a set has
     */
    public function __construct(
        private RecoveryCodeStore $store,
        private Credentials $credentials,
        private Sessions $sessions,
        private StepUp $stepUp,
        private Attempts $attempts,
        private SecurityLog $log,
        private Transactions $transactions,
        private Principals $principals,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private ClockInterface $clock = new NativeClock(),
        private int $count = 10,
        private IdGenerator $ids = new UuidV7Generator(),
    ) {
        if ($count < 1 || $count > 100) {
            throw new IntegratorError(\sprintf('A set of %d recovery codes is out of range.', $count), 'Use between 1 and 100; 10 is usual.', 'concepts/recovery-codes.md#configuration');
        }
    }

    /**
     * Generates the session's principal a new set of codes, replacing any earlier ones.
     *
     * @return list<Secret> The codes, e.g. "7KQ2-M9XH-…", shown only now
     *
     * @throws StepUpRequired    When the session didn't prove who it is recently enough
     * @throws HeldAfterRecovery When the account was recovered recently, and it has codes
     */
    public function generate(GenerateRecoveryCodes $command, Session $session, Origin $origin): array
    {
        if (!$command->realm->equals($session->realm)) {
            throw new IntegratorError('The session is in another realm than the recovery codes.', 'Generate codes in the realm of the signed-in session.', 'concepts/recovery-codes.md#generating-codes');
        }

        return $this->transactions->run(function () use ($session, $origin): array {
            $credential = $this->credential($session->realm, $session->principalId);

            if (null === $credential) {
                $credential = $this->credentials->add(new AddCredential($session->realm, CredentialKind::RecoveryCodes, ''), $session, $origin);
            } else {
                // A new set makes the owner's saved codes useless: it waits out a recovery's cool-down, and
                // needs a passkey's proof, not a code's.
                $this->credentials->requireStepUp($session, self::GENERATE, heldAfterRecovery: true, phishingResistant: true);
            }

            $codes = [];
            $hashes = [];

            for ($i = 0; $i < $this->count; ++$i) {
                $code = HumanCode::random(self::LENGTH, 4);
                $codes[] = Secret::fromString($code);
                $hashes[] = self::hash($code);
            }

            // Recording first takes the realm's log lock, so two new sets at once are made one after the
            // other: the later replaces the earlier, and only one set works.
            $this->log->record($session->realm, self::GENERATE, Outcome::Success, $origin, $session->principalId, 'credential', (string) $credential->id, [
                'count' => $this->count,
                'principal' => (string) $session->principalId,
            ]);
            $this->store->replace($session->realm, $session->principalId, $hashes, Time::stored($this->clock->now()));

            $this->transactions->afterCommit(function () use ($session, $origin): void {
                $this->events->dispatch(new RecoveryCodesGenerated($session->realm, $session->principalId, $this->count, $origin));
            });

            return $codes;
        });
    }

    /**
     * Recovers an account with one of its codes, finding it from what the person typed, e.g. their email
     * address: ends all of its sessions, and starts one. Use this for anything a person enters.
     *
     * The answer, the attempts counted and the work done are the same whether there's no such account, it
     * has no codes, or the code is wrong; attempts are counted for the identifier typed, not the account.
     *
     * @param string      $identifier   As typed; one that isn't valid for its kind matches no account
     * @param string      $code         As typed; case, hyphens and spaces don't matter
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession; pass it, so attempts from one address are limited
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     *
     * @return StartedSession|null The new session; null if there's no such account or the code doesn't work
     *
     * @throws InactivePrincipal When the account isn't active and the code is right; the code still works afterwards
     * @throws Throttled         When there were too many attempts; nothing was checked
     */
    public function recover(Realm $realm, IdentifierKind $kind, string $identifier, #[\SensitiveParameter] string $code, Origin $origin, ?string $device = null, ?string $address = null, #[\SensitiveParameter] ?Secret $deviceCookie = null): ?StartedSession
    {
        try {
            $account = \sprintf('%s:%s', $kind->value, $kind->normalize($identifier));
        } catch (InvalidIdentifier) {
            $account = null;
        }

        $principalId = null === $account ? null : $this->principals->findByIdentifier($realm, $kind, $identifier)?->id;

        if (null === $principalId) {
            // An account that doesn't exist is looked up and checked like one without codes, under an ID
            // nothing has.
            $principalId = new PrincipalId($this->ids->next());
            $this->principals->get($realm, $principalId);
        }

        // The owner's own browser isn't kept out by attempts at the account from elsewhere.
        $this->attempts->count('recovery_code', $realm, $account, $address, $this->sessions->isKnownDevice($realm, $principalId, $deviceCookie));

        $started = $this->check($realm, $principalId, self::hash($code), $origin, $device, $address, $deviceCookie);

        if (null !== $started) {
            \assert(null !== $account, 'Only an account that exists has codes.');
            $this->attempts->succeeded('recovery_code', $realm, $account, $address);
        }

        return $started;
    }

    /**
     * Recovers the principal's account with one of its codes: ends all of its sessions, and starts one.
     * When the person types who they are, use recover() instead, which answers the same for accounts that
     * don't exist.
     *
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession; pass it, so attempts from one address are limited
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     *
     * @return StartedSession|null The new session; null if the code doesn't work
     *
     * @throws InactivePrincipal When the principal isn't active; the code still works afterwards
     * @throws Throttled         When there were too many attempts; nothing was checked
     */
    public function redeem(Realm $realm, PrincipalId $principalId, #[\SensitiveParameter] Secret $code, Origin $origin, ?string $device = null, ?string $address = null, #[\SensitiveParameter] ?Secret $deviceCookie = null): ?StartedSession
    {
        $this->attempts->count('recovery_code', $realm, $principalId, $address, $this->sessions->isKnownDevice($realm, $principalId, $deviceCookie));
        $started = $this->check($realm, $principalId, self::hash($code->reveal()), $origin, $device, $address, $deviceCookie);

        if (null !== $started) {
            $this->attempts->succeeded('recovery_code', $realm, $principalId, $address);
        }

        return $started;
    }

    /**
     * How many codes the principal has left; 0 if it has none. Suggest a new set when few are left.
     */
    public function remaining(Realm $realm, PrincipalId $principalId): int
    {
        return null === $this->credential($realm, $principalId) ? 0 : $this->store->count($realm, $principalId);
    }

    /**
     * Uses the code if it's one of the principal's, in the same statements whether the principal has no
     * codes or the code is wrong: a refusal is recorded either way.
     */
    private function check(Realm $realm, PrincipalId $principalId, string $hash, Origin $origin, ?string $device, ?string $address, ?Secret $deviceCookie): ?StartedSession
    {
        return $this->transactions->run(function () use ($realm, $principalId, $hash, $origin, $device, $address, $deviceCookie): ?StartedSession {
            $credential = $this->credential($realm, $principalId);
            $usable = null !== $credential && $credential->isActive();

            if (!$usable) {
                // No codes to use, or a disabled set, which keeps its codes: a read instead of the use.
                $this->store->count($realm, $principalId);
                $this->log->record($realm, self::USE, Outcome::Failed, $origin);

                return null;
            }

            if (!$this->store->use($realm, $principalId, $hash)) {
                $this->log->record($realm, self::USE, Outcome::Failed, $origin, null, 'credential', (string) $credential->id, ['principal' => (string) $principalId]);

                return null;
            }

            $remaining = $this->store->count($realm, $principalId);
            $this->credentials->markUsed($credential);
            $this->log->record($realm, self::USE, Outcome::Success, $origin, $principalId, 'credential', (string) $credential->id, ['principal' => (string) $principalId, 'remaining' => $remaining]);
            $this->sessions->endAll(new EndSessions($realm, $principalId, SessionEndReason::Recovered), $origin, $principalId);
            $this->stepUp->startCoolDown($realm, $principalId, $origin);
            $started = $this->sessions->start(new StartSession($realm, $principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryCode, $this->clock->now())), $device, $address, $deviceCookie), $origin);

            $this->transactions->afterCommit(function () use ($realm, $principalId, $remaining, $origin): void {
                $this->events->dispatch(new RecoveryCodeUsed($realm, $principalId, $remaining, $origin));
            });

            return $started;
        });
    }

    private function credential(Realm $realm, PrincipalId $principalId): ?Credential
    {
        foreach ($this->credentials->of($realm, $principalId) as $credential) {
            if (CredentialKind::RecoveryCodes === $credential->kind) {
                return $credential;
            }
        }

        return null;
    }

    private static function hash(#[\SensitiveParameter] string $code): string
    {
        return hash('sha256', HumanCode::normalize($code));
    }
}
