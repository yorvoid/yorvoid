<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery\Storage;

use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * @implements InMemoryStore<array<string, array<string, array<string, true>>>>
 *
 * @api
 */
final class InMemoryRecoveryCodeStore implements RecoveryCodeStore, InMemoryStore
{
    /** @var array<string, array<string, array<string, true>>> By realm, then principal: the hashes */
    private array $codes = [];

    public function replace(Realm $realm, PrincipalId $principalId, array $hashes, \DateTimeImmutable $createdAt): void
    {
        $this->codes[$realm->name][(string) $principalId] = array_fill_keys($hashes, true);
    }

    public function use(Realm $realm, PrincipalId $principalId, string $hash): bool
    {
        if (!isset($this->codes[$realm->name][(string) $principalId][$hash])) {
            return false;
        }

        unset($this->codes[$realm->name][(string) $principalId][$hash]);

        return true;
    }

    public function count(Realm $realm, PrincipalId $principalId): int
    {
        return \count($this->codes[$realm->name][(string) $principalId] ?? []);
    }

    public function snapshot(): mixed
    {
        return $this->codes;
    }

    public function restore(mixed $snapshot): void
    {
        $this->codes = $snapshot;
    }
}
