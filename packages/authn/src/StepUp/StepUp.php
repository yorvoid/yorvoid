<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Time;

/**
 * Whether a session may run a sensitive operation now (ATN-070, ATN-071, ATN-029): it proved who it is
 * recently and strongly enough for it, and the account isn't cooling down after a recovery.
 *
 * The cool-down is off unless configured. With it, an account recovery (with a recovery code or link)
 * holds the operations that ask for it, e.g. removing a passkey, for that long: someone who recovered an
 * account that isn't theirs can't lock its owner out before the owner notices.
 *
 * @api
 */
final readonly class StepUp
{
    /**
     * @param Duration|null $coolDown How long sensitive operations are held after an account recovery; none if not given
     */
    public function __construct(
        private Sessions $sessions,
        private CoolDownStore $coolDowns,
        private SecurityLog $log,
        private ClockInterface $clock = new NativeClock(),
        private ?Duration $coolDown = null,
    ) {
    }

    /**
     * @throws HeldAfterRecovery When the operation waits out a cool-down
     * @throws StepUpRequired    When the session is ended, or hasn't proved who it is as required
     */
    public function require(Session $session, AuthenticationRequirement $requirement): void
    {
        $current = $this->sessions->get($session->realm, $session->id);
        $now = $this->clock->now();

        if (null === $current || !$current->isCurrentAt($now)) {
            throw new StepUpRequired($requirement);
        }

        $until = $requirement->heldAfterRecovery ? $this->coolDownEndsAt($current->realm, $current->principalId) : null;

        if (null !== $until) {
            throw new HeldAfterRecovery($until);
        }

        if (!$requirement->isMetBy($current->authentication, $now)) {
            throw new StepUpRequired($requirement);
        }
    }

    /**
     * Starts the cool-down after an account recovery, if one is configured. For the ways to recover.
     */
    public function startCoolDown(Realm $realm, PrincipalId $principalId, Origin $origin): void
    {
        if (null === $this->coolDown) {
            return;
        }

        $until = $this->coolDown->after(Time::stored($this->clock->now()));
        // Recording first takes the realm's log lock: two recoveries at once extend one after the other.
        $this->log->record($realm, 'yorvoid.recovery.cool_down', Outcome::Success, $origin, null, 'principal', (string) $principalId, ['until' => $until->format(\DATE_ATOM)]);
        $this->coolDowns->extend($realm, $principalId, $until);
    }

    /**
     * When the principal's cool-down ends; null if it isn't cooling down.
     */
    public function coolDownEndsAt(Realm $realm, PrincipalId $principalId): ?\DateTimeImmutable
    {
        $until = $this->coolDowns->until($realm, $principalId);

        return null !== $until && $this->clock->now() < $until ? $until : null;
    }

    /**
     * Removes cool-downs that ended; run it daily, e.g. from a scheduled job.
     *
     * @return int How many
     */
    public function purge(): int
    {
        return $this->coolDowns->purge($this->clock->now());
    }
}
