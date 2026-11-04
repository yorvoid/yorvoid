<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Loses every conditional write: another request ended the session, or renewed its token, first.
 */
final readonly class LosingSessionStore implements SessionStore
{
    public function __construct(
        private SessionStore $store,
    ) {
    }

    public function add(Session $session, string $tokenHash): void
    {
        $this->store->add($session, $tokenHash);
    }

    public function findByTokenHash(string $tokenHash): ?Session
    {
        return $this->store->findByTokenHash($tokenHash);
    }

    public function get(Realm $realm, SessionId $id): ?Session
    {
        return $this->store->get($realm, $id);
    }

    public function current(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $now): array
    {
        return $this->store->current($realm, $principalId, $now);
    }

    public function replaceTokenHash(Realm $realm, SessionId $id, string $tokenHash): bool
    {
        return false;
    }

    public function stepUp(Realm $realm, SessionId $id, AuthenticationFactor $factor, string $tokenHash): ?AuthenticationContext
    {
        return null;
    }

    public function touch(Realm $realm, SessionId $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $idleExpiresAt): void
    {
        $this->store->touch($realm, $id, $lastSeenAt, $idleExpiresAt);
    }

    public function end(Realm $realm, SessionId $id, \DateTimeImmutable $endedAt, SessionEndReason $reason): bool
    {
        return $this->store->end($realm, $id, $endedAt, $reason);
    }

    public function endCurrent(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $now, SessionEndReason $reason, ?SessionId $except = null): array
    {
        return $this->store->endCurrent($realm, $principalId, $now, $reason, $except);
    }

    public function purge(\DateTimeImmutable $before): int
    {
        return $this->store->purge($before);
    }
}
