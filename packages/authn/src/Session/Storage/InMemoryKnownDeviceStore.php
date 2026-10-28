<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Storage;

use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * @implements InMemoryStore<array<string, array<string, array<string, \DateTimeImmutable>>>>
 *
 * @api
 */
final class InMemoryKnownDeviceStore implements KnownDeviceStore, InMemoryStore
{
    /** @var array<string, array<string, array<string, \DateTimeImmutable>>> By realm, then principal, then cookie hash: last seen */
    private array $devices = [];

    public function recordUse(Realm $realm, PrincipalId $principalId, string $cookieHash, \DateTimeImmutable $at): bool
    {
        $known = isset($this->devices[$realm->name][(string) $principalId][$cookieHash]);
        $this->devices[$realm->name][(string) $principalId][$cookieHash] = $at;

        return $known;
    }

    public function hasAny(Realm $realm, PrincipalId $principalId): bool
    {
        return [] !== ($this->devices[$realm->name][(string) $principalId] ?? []);
    }

    public function purge(\DateTimeImmutable $unusedSince): int
    {
        $purged = 0;

        foreach ($this->devices as $realm => $principals) {
            foreach ($principals as $principal => $devices) {
                $kept = array_filter($devices, static fn (\DateTimeImmutable $lastSeen): bool => $lastSeen >= $unusedSince);
                $purged += \count($devices) - \count($kept);
                $this->devices[$realm][$principal] = $kept;
            }
        }

        return $purged;
    }

    public function snapshot(): mixed
    {
        return $this->devices;
    }

    public function restore(mixed $snapshot): void
    {
        $this->devices = $snapshot;
    }
}
