<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Storage;

use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationStatus;
use YorvoID\Delegation\DelegationStore;

/**
 * @implements InMemoryStore<array{delegations?: array<string, Delegation>, versions?: array<string, int>}>
 *
 * @api
 *
 * @experimental
 */
final class InMemoryDelegationStore implements DelegationStore, InMemoryStore
{
    /** @var array<string, Delegation> By ID, in the order added */
    private array $delegations = [];

    /** @var array<string, int> By realm */
    private array $versions = [];

    public function add(Delegation $delegation): void
    {
        $id = (string) $delegation->id;

        if (isset($this->delegations[$id])) {
            throw new StorageException(\sprintf('A delegation with the ID %s exists.', $id));
        }

        $this->bump($delegation->realm);
        $this->delegations[$id] = $delegation;
    }

    public function get(Realm $realm, DelegationId $id): ?Delegation
    {
        $delegation = $this->delegations[(string) $id] ?? null;

        return null !== $delegation && $delegation->realm->equals($realm) ? $delegation : null;
    }

    public function involving(Realm $realm, PrincipalId $principal): array
    {
        $found = array_values(array_filter($this->delegations, static fn (Delegation $delegation): bool => $delegation->realm->equals($realm) && $delegation->isActive() && ($delegation->delegator->equals($principal) || $delegation->delegate->equals($principal))));
        usort($found, static fn (Delegation $a, Delegation $b): int => [$a->issuedAt, (string) $a->id] <=> [$b->issuedAt, (string) $b->id]);

        return $found;
    }

    public function revoke(Delegation $before, Delegation $after): bool
    {
        if (DelegationStatus::Active !== $this->get($before->realm, $before->id)?->status) {
            return false;
        }

        $this->bump($before->realm);
        $this->delegations[(string) $before->id] = $after;

        return true;
    }

    public function version(Realm $realm): int
    {
        return $this->versions[$realm->name] ?? 0;
    }

    public function snapshot(): mixed
    {
        return ['delegations' => $this->delegations, 'versions' => $this->versions];
    }

    public function restore(mixed $snapshot): void
    {
        $this->delegations = $snapshot['delegations'] ?? [];
        $this->versions = $snapshot['versions'] ?? [];
    }

    private function bump(Realm $realm): void
    {
        $this->versions[$realm->name] = $this->version($realm) + 1;
    }
}
