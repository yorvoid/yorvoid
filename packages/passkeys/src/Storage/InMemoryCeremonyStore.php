<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Storage;

use Symfony\Component\Uid\Uuid;
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
        $this->ceremonies[$ceremony->id->toRfc4122()] = $ceremony;
    }

    public function take(Realm $realm, Uuid $id): ?Ceremony
    {
        $ceremony = $this->ceremonies[$id->toRfc4122()] ?? null;

        if (null === $ceremony || !$ceremony->realm->equals($realm)) {
            return null;
        }

        unset($this->ceremonies[$id->toRfc4122()]);

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
