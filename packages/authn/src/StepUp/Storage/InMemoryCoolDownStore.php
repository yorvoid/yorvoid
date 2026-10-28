<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp\Storage;

use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * @implements InMemoryStore<array<string, array<string, \DateTimeImmutable>>>
 *
 * @api
 */
final class InMemoryCoolDownStore implements CoolDownStore, InMemoryStore
{
    /** @var array<string, array<string, \DateTimeImmutable>> By realm, then principal: when it ends */
    private array $coolDowns = [];

    public function extend(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $until): void
    {
        $this->coolDowns[$realm->name][(string) $principalId] = max($this->coolDowns[$realm->name][(string) $principalId] ?? $until, $until);
    }

    public function until(Realm $realm, PrincipalId $principalId): ?\DateTimeImmutable
    {
        return $this->coolDowns[$realm->name][(string) $principalId] ?? null;
    }

    public function purge(\DateTimeImmutable $before): int
    {
        $purged = 0;

        foreach ($this->coolDowns as $realm => $coolDowns) {
            $kept = array_filter($coolDowns, static fn (\DateTimeImmutable $until): bool => $until >= $before);
            $purged += \count($coolDowns) - \count($kept);
            $this->coolDowns[$realm] = $kept;
        }

        return $purged;
    }

    public function snapshot(): mixed
    {
        return $this->coolDowns;
    }

    public function restore(mixed $snapshot): void
    {
        $this->coolDowns = $snapshot;
    }
}
