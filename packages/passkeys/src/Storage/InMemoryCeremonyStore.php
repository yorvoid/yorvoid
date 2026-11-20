<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Storage;

use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Id\Id;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Passkeys\Ceremony;
use YorvoID\Passkeys\CeremonyStore;

/**
 * @implements InMemoryStore<array<string, Ceremony>>
 *
 * @api
 */
final class InMemoryCeremonyStore implements CeremonyStore, InMemoryStore
{
    /** @var array<string, Ceremony> By ID */
    private array $ceremonies = [];

    public function add(Ceremony $ceremony): void
    {
        $this->ceremonies[$ceremony->id->toString()] = $ceremony;
    }

    public function take(Realm $realm, Id $id, ?SessionId $session = null): ?Ceremony
    {
        $ceremony = $this->ceremonies[$id->toString()] ?? null;

        if (null === $ceremony || !$ceremony->realm->equals($realm) || $ceremony->sessionId?->id->toString() !== $session?->id->toString()) {
            return null;
        }

        unset($this->ceremonies[$id->toString()]);

        return $ceremony;
    }

    public function purge(\DateTimeImmutable $now): int
    {
        $count = \count($this->ceremonies);
        $this->ceremonies = array_filter($this->ceremonies, static fn (Ceremony $ceremony): bool => $ceremony->expiresAt > $now);

        return $count - \count($this->ceremonies);
    }

    public function snapshot(): mixed
    {
        return $this->ceremonies;
    }

    public function restore(mixed $snapshot): void
    {
        $this->ceremonies = $snapshot;
    }
}
