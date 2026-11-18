<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\ProvesActors;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\Actor;

/**
 * Proves a session actor by reading it from its store, for every decision (D-45, AR-05): the decision
 * reads the session as recorded, its authentication factors included, never the one it was given. One
 * that isn't on record, has ended or expired, or is another principal's, is refused.
 *
 * @api
 */
final readonly class StoredSessions implements ProvesActors
{
    public function __construct(
        private SessionStore $sessions,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function proves(Actor $actor): bool
    {
        return $actor instanceof Session;
    }

    public function prove(Actor $actor): Actor|Truth
    {
        \assert($actor instanceof Session);
        $stored = $this->sessions->get($actor->realm, $actor->id);

        if (null === $stored || !$stored->principalId->equals($actor->principalId)) {
            return Truth::fails(\sprintf('The session %s isn\'t on record.', $actor->id))->grounded(Ground::authority('unknown_session'));
        }

        if ($stored->isEnded()) {
            return Truth::fails(\sprintf('The session %s has ended.', $actor->id))->grounded(Ground::authority('session_ended'));
        }

        if (!$stored->isCurrentAt($this->clock->now())) {
            return Truth::fails(\sprintf('The session %s has expired.', $actor->id))->grounded(Ground::authority('session_expired'));
        }

        return $stored;
    }
}
