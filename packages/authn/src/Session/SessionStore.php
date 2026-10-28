<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where sessions are kept. Tokens are stored only as their SHA-256 hashes, so a leaked database (or
 * backup) holds no usable session.
 *
 * @api
 */
interface SessionStore
{
    public function add(Session $session, string $tokenHash): void;

    public function findByTokenHash(string $tokenHash): ?Session;

    public function get(Realm $realm, SessionId $id): ?Session;

    /**
     * The principal's sessions that aren't ended or past their limits at that moment, the most recently
     * used first.
     *
     * @return list<Session>
     */
    public function current(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $now): array;

    /**
     * Gives a session that isn't ended a new token.
     *
     * @return bool Whether it did
     */
    public function replaceTokenHash(Realm $realm, SessionId $id, string $tokenHash): bool;

    /**
     * Gives a session that isn't ended its new authentication context and a new token, together.
     *
     * @return bool Whether it did
     */
    public function stepUp(Realm $realm, SessionId $id, AuthenticationContext $authentication, string $tokenHash): bool;

    public function touch(Realm $realm, SessionId $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $idleExpiresAt): void;

    /**
     * Ends a session that isn't ended.
     *
     * @return bool Whether it did; false if it's unknown or already ended
     */
    public function end(Realm $realm, SessionId $id, \DateTimeImmutable $endedAt, SessionEndReason $reason): bool;

    /**
     * Removes the sessions that ended or expired before the moment, in every realm.
     *
     * @return int How many
     */
    public function purge(\DateTimeImmutable $before): int;
}
