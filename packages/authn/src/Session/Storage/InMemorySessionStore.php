<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Storage;

use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Core\Storage\StorageException;

/**
 * @implements InMemoryStore<array<string, array{Session, string}>>
 *
 * @api
 */
final class InMemorySessionStore implements SessionStore, InMemoryStore
{
    /** @var array<string, array{Session, string}> By ID: the session and its token's hash */
    private array $sessions = [];

    public function add(Session $session, string $tokenHash): void
    {
        foreach ($this->sessions as $id => [, $hash]) {
            if ($id === (string) $session->id || $hash === $tokenHash) {
                throw new StorageException(\sprintf('A session with the ID %s or the same token exists.', $session->id));
            }
        }

        $this->sessions[(string) $session->id] = [$session, $tokenHash];
    }

    public function findByTokenHash(string $tokenHash): ?Session
    {
        foreach ($this->sessions as [$session, $hash]) {
            if ($hash === $tokenHash) {
                return $session;
            }
        }

        return null;
    }

    public function get(Realm $realm, SessionId $id): ?Session
    {
        $session = $this->sessions[(string) $id][0] ?? null;

        return null !== $session && $session->realm->equals($realm) ? $session : null;
    }

    public function current(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $now): array
    {
        $current = [];

        foreach ($this->sessions as [$session]) {
            if ($session->realm->equals($realm) && $session->principalId->equals($principalId) && $session->isCurrentAt($now)) {
                $current[] = $session;
            }
        }

        usort($current, static fn (Session $a, Session $b): int => [$b->lastSeenAt, $b->id->uuid->toRfc4122()] <=> [$a->lastSeenAt, $a->id->uuid->toRfc4122()]);

        return $current;
    }

    public function replaceTokenHash(Realm $realm, SessionId $id, string $tokenHash): bool
    {
        $session = $this->get($realm, $id);

        if (null === $session || $session->isEnded()) {
            return false;
        }

        $this->sessions[(string) $id] = [$session, $tokenHash];

        return true;
    }

    public function stepUp(Realm $realm, SessionId $id, AuthenticationContext $authentication, string $tokenHash): bool
    {
        $session = $this->get($realm, $id);

        if (null === $session || $session->isEnded()) {
            return false;
        }

        $this->sessions[(string) $id] = [new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $authentication, $session->startedAt, $session->lastSeenAt, $session->expiresAt, $session->idleExpiresAt, $session->device, $session->address), $tokenHash];

        return true;
    }

    public function touch(Realm $realm, SessionId $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $idleExpiresAt): void
    {
        $session = $this->get($realm, $id);

        if (null !== $session) {
            $this->replace($session, new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $session->authentication, $session->startedAt, $lastSeenAt, $session->expiresAt, $idleExpiresAt, $session->device, $session->address, $session->endedAt, $session->endReason));
        }
    }

    public function end(Realm $realm, SessionId $id, \DateTimeImmutable $endedAt, SessionEndReason $reason): bool
    {
        $session = $this->get($realm, $id);

        if (null === $session || $session->isEnded()) {
            return false;
        }

        $this->replace($session, new Session($session->id, $session->realm, $session->principalId, $session->principalStatusVersion, $session->authentication, $session->startedAt, $session->lastSeenAt, $session->expiresAt, $session->idleExpiresAt, $session->device, $session->address, $endedAt, $reason));

        return true;
    }

    public function purge(\DateTimeImmutable $before): int
    {
        $count = \count($this->sessions);
        $this->sessions = array_filter($this->sessions, static fn (array $entry): bool => !((null !== $entry[0]->endedAt && $entry[0]->endedAt < $before) || $entry[0]->expiresAt < $before || $entry[0]->idleExpiresAt < $before));

        return $count - \count($this->sessions);
    }

    public function snapshot(): mixed
    {
        return $this->sessions;
    }

    public function restore(mixed $snapshot): void
    {
        $this->sessions = $snapshot;
    }

    private function replace(Session $old, Session $new): void
    {
        $this->sessions[(string) $old->id] = [$new, $this->sessions[(string) $old->id][1]];
    }
}
