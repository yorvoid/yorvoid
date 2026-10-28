<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Session\Event\NewDeviceSignedIn;
use YorvoID\Authn\Session\Event\SessionEnded;
use YorvoID\Authn\Session\Event\SessionRefreshed;
use YorvoID\Authn\Session\Event\SessionStarted;
use YorvoID\Authn\Session\Event\SessionStarting;
use YorvoID\Authn\Session\Event\SessionSteppedUp;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCancelled;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Text;
use YorvoID\Core\Time;

/**
 * Authentication sessions, kept server-side (SES-001…005, SES-011, SES-013): started once someone has
 * proved who they are, checked on every request, ended by signing out, by their principal elsewhere, by
 * an administrator, or by their principal's suspension.
 *
 * A session is checked against the database on every request, so ending it takes effect at the next
 * request on every server (SES-005). It's valid only while it's within its limits, not ended, and its
 * principal is active and hasn't changed status since it started: a session doesn't come back when a
 * suspension is undone.
 *
 * With a KnownDeviceStore, sessions also recognise devices (SES-012) by a long-lived random cookie,
 * never by fingerprinting: signing in from a device the principal hasn't used before, while it has used
 * others, is announced with NewDeviceSignedIn (ATN-080).
 *
 * @api
 */
final readonly class Sessions
{
    /** How long a session's last use may be off, to save a write on every request */
    public const int TOUCH_INTERVAL = 60;

    private SessionPolicy $policy;

    /**
     * @param SessionPolicy|null    $policy  AAL2's limits if not given: 24 hours, and 1 hour idle
     * @param KnownDeviceStore|null $devices Where known devices are kept; without it, devices aren't recognised
     */
    public function __construct(
        private SessionStore $store,
        private Principals $principals,
        private SecurityLog $log,
        private Transactions $transactions,
        ?SessionPolicy $policy = null,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private IdGenerator $ids = new UuidV7Generator(),
        private ClockInterface $clock = new NativeClock(),
        private ?KnownDeviceStore $devices = null,
    ) {
        $this->policy = $policy ?? SessionPolicy::aal2();
    }

    /**
     * Starts a session and gives its token, which is shown only now.
     *
     * @throws UnknownPrincipal   When there's no such principal
     * @throws InactivePrincipal  When the principal isn't active
     * @throws OperationCancelled When a listener of SessionStarting refuses it
     */
    public function start(StartSession $command, Origin $origin): StartedSession
    {
        if (null !== $command->address && false === filter_var($command->address, \FILTER_VALIDATE_IP)) {
            throw new IntegratorError('The session\'s address isn\'t an IP address.', 'Pass the IP address of the request, e.g. from Request::getClientIp() in Symfony, or null.', 'concepts/sessions.md#starting-a-session');
        }

        return $this->transactions->run(function () use ($command, $origin): StartedSession {
            $principal = $this->principals->get($command->realm, $command->principalId) ?? throw new UnknownPrincipal($command->realm, $command->principalId);

            if (!$principal->isActive()) {
                throw new InactivePrincipal($principal);
            }

            $now = Time::stored($this->clock->now());
            $device = null === $command->device ? null : mb_substr(Text::utf8($command->device), 0, 255);
            $session = new Session(new SessionId($this->ids->next()), $command->realm, $principal->id, $principal->statusVersion, $command->authentication, $now, $now, $this->policy->absolute->after($now), $this->policy->idle->after($now), $device, $command->address);
            $token = self::newToken();

            $starting = new SessionStarting($session, $origin);
            $this->events->dispatch($starting);

            if ($starting->isCancelled()) {
                throw new OperationCancelled('yorvoid.session.start', (string) $starting->reason());
            }

            $this->store->add($session, self::hash($token));
            [$deviceCookie, $newDevice] = $this->recognize($session, $command->deviceCookie, $now);
            $this->log->record($session->realm, 'yorvoid.session.start', Outcome::Success, $origin, $principal->id, 'session', (string) $session->id, [
                'methods' => array_map(static fn (AuthenticationMethod $method): string => $method->value, $session->authentication->methods()),
                'principal' => (string) $principal->id,
                ...(null === $deviceCookie ? [] : ['new_device' => $newDevice]),
            ]);

            $this->transactions->afterCommit(function () use ($session, $origin, $newDevice): void {
                $this->events->dispatch(new SessionStarted($session, $origin));

                if ($newDevice) {
                    $this->events->dispatch(new NewDeviceSignedIn($session, $origin));
                }
            });

            return new StartedSession($session, $token, $deviceCookie, $newDevice);
        });
    }

    /**
     * The session this token belongs to, if it's valid now in this realm; null otherwise, for whatever
     * reason. Records that it was used.
     */
    public function validate(Realm $realm, #[\SensitiveParameter] Secret $token): ?Session
    {
        $session = $this->store->findByTokenHash(self::hash($token));
        $now = $this->clock->now();

        if (null === $session || !$session->realm->equals($realm) || !$this->isValidAt($session, $now)) {
            return null;
        }

        $principal = $this->principals->get($realm, $session->principalId);

        if (null === $principal || !$principal->isActive() || $principal->statusVersion !== $session->principalStatusVersion) {
            return null;
        }

        if ($now->getTimestamp() - $session->lastSeenAt->getTimestamp() < self::TOUCH_INTERVAL) {
            return $session;
        }

        $lastSeenAt = Time::stored($now);
        $idleExpiresAt = $this->policy->idle->after($lastSeenAt);
        $this->store->touch($realm, $session->id, $lastSeenAt, $idleExpiresAt);

        return new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $session->authentication, $session->startedAt, $lastSeenAt, $session->expiresAt, $idleExpiresAt, $session->device, $session->address);
    }

    /**
     * Gives a current session a new token (SES-002): after signing in to an existing session, after
     * stepping up, after any change of privilege. The old token stops working.
     *
     * @return Secret|null The new token; null if the session isn't current
     */
    public function refresh(Realm $realm, SessionId $id, Origin $origin): ?Secret
    {
        return $this->transactions->run(function () use ($realm, $id, $origin): ?Secret {
            $session = $this->store->get($realm, $id);

            if (null === $session || !$this->isValidAt($session, $this->clock->now())) {
                return null;
            }

            $token = self::newToken();

            if (!$this->store->replaceTokenHash($realm, $id, self::hash($token))) {
                return null;
            }

            $this->transactions->afterCommit(function () use ($session, $origin): void {
                $this->events->dispatch(new SessionRefreshed($session, $origin));
            });

            return $token;
        });
    }

    /**
     * Adds a proof to a current session, after its principal proved who they are again (ATN-070), and
     * gives it a new token (SES-002): the old token stops working. For the packages that check the proof,
     * e.g. Passkeys::finishStepUp().
     *
     * @return Secret|null The new token; null if the session isn't current, or its principal not active
     */
    public function stepUp(Realm $realm, SessionId $id, AuthenticationFactor $factor, Origin $origin): ?Secret
    {
        return $this->transactions->run(function () use ($realm, $id, $factor, $origin): ?Secret {
            $session = $this->store->get($realm, $id);

            if (null === $session || !$this->isValidAt($session, $this->clock->now())) {
                return null;
            }

            $principal = $this->principals->get($realm, $session->principalId);

            if (null === $principal || !$principal->isActive() || $principal->statusVersion !== $session->principalStatusVersion) {
                return null;
            }

            $token = self::newToken();
            $authentication = $session->authentication->with($factor);

            if (!$this->store->stepUp($realm, $id, $authentication, self::hash($token))) {
                return null;
            }

            $this->log->record($realm, 'yorvoid.session.step_up', Outcome::Success, $origin, $session->principalId, 'session', (string) $session->id, [
                'method' => $factor->method->value,
                'operation' => $factor->operation,
                'principal' => (string) $session->principalId,
            ]);

            $steppedUp = new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $authentication, $session->startedAt, $session->lastSeenAt, $session->expiresAt, $session->idleExpiresAt, $session->device, $session->address);
            $this->transactions->afterCommit(function () use ($steppedUp, $factor, $origin): void {
                $this->events->dispatch(new SessionSteppedUp($steppedUp, $factor, $origin));
            });

            return $token;
        });
    }

    /**
     * Ends a session; nothing happens if it already ended.
     *
     * @param PrincipalId|null $actor Who ended it, if not the session's own principal signing out
     *
     * @return bool Whether it ended now
     */
    public function end(EndSession $command, Origin $origin, ?PrincipalId $actor = null): bool
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): bool {
            $session = $this->store->get($command->realm, $command->sessionId);

            return null !== $session && $this->endOne($session, $command->reason, $origin, $actor);
        });
    }

    /**
     * Ends all of a principal's current sessions, or all but one.
     *
     * @return int How many ended
     */
    public function endAll(EndSessions $command, Origin $origin, ?PrincipalId $actor = null): int
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): int {
            $ended = 0;

            foreach ($this->store->current($command->realm, $command->principalId, $this->clock->now()) as $session) {
                if ((null === $command->except || !$session->id->equals($command->except)) && $this->endOne($session, $command->reason, $origin, $actor)) {
                    ++$ended;
                }
            }

            return $ended;
        });
    }

    /**
     * A principal's current sessions, the most recently used first: for "your devices" (SES-004) and
     * for administrators (SES-013).
     *
     * @return list<Session>
     */
    public function current(Realm $realm, PrincipalId $principalId): array
    {
        $now = $this->clock->now();

        return array_values(array_filter($this->store->current($realm, $principalId, $now), fn (Session $session): bool => $this->isValidAt($session, $now)));
    }

    public function get(Realm $realm, SessionId $id): ?Session
    {
        return $this->store->get($realm, $id);
    }

    /**
     * Removes sessions that ended or expired more than $keep ago; run it daily, e.g. from a scheduled job.
     *
     * @return int How many
     */
    public function purge(Duration $keep): int
    {
        return $this->store->purge(new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() - $keep->seconds)));
    }

    /**
     * Forgets devices not used for that long; run it daily, with a period longer than the device
     * cookie's lifetime.
     *
     * @return int How many
     */
    public function purgeDevices(Duration $unusedFor): int
    {
        return null === $this->devices ? 0 : $this->devices->purge(new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() - $unusedFor->seconds)));
    }

    /**
     * The device cookie to set, and whether the device is new to a principal that has used others.
     *
     * @return array{Secret|null, bool}
     */
    private function recognize(Session $session, ?Secret $cookie, \DateTimeImmutable $now): array
    {
        if (null === $this->devices) {
            return [null, false];
        }

        $cookie ??= self::newToken();
        $usedOthers = $this->devices->hasAny($session->realm, $session->principalId);
        $known = $this->devices->recordUse($session->realm, $session->principalId, self::hash($cookie), $now);

        return [$cookie, $usedOthers && !$known];
    }

    private function endOne(Session $session, SessionEndReason $reason, Origin $origin, ?PrincipalId $actor): bool
    {
        $now = Time::stored($this->clock->now());

        if (!$this->store->end($session->realm, $session->id, $now, $reason)) {
            return false;
        }

        $ended = new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $session->authentication, $session->startedAt, $session->lastSeenAt, $session->expiresAt, $session->idleExpiresAt, $session->device, $session->address, $now, $reason);
        $this->log->record($session->realm, 'yorvoid.session.end', Outcome::Success, $origin, $actor, 'session', (string) $session->id, [
            'principal' => (string) $session->principalId,
            'reason' => $reason->value,
        ]);

        $this->transactions->afterCommit(function () use ($ended, $origin, $actor): void {
            $this->events->dispatch(new SessionEnded($ended, $origin, $actor));
        });

        return true;
    }

    /**
     * Within its own limits, and within the current policy's: a tightened policy applies at once.
     */
    private function isValidAt(Session $session, \DateTimeImmutable $now): bool
    {
        return $session->isCurrentAt($now)
            && $now < $this->policy->absolute->after($session->startedAt)
            && $now < $this->policy->idle->after($session->lastSeenAt);
    }

    private static function newToken(): Secret
    {
        return Secret::fromString(rtrim(strtr(base64_encode(Secret::random(32)->reveal()), '+/', '-_'), '='));
    }

    private static function hash(#[\SensitiveParameter] Secret $token): string
    {
        return hash('sha256', $token->reveal());
    }
}
