<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Storage;

use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Core\Storage\StorageException;

/**
 * @implements InMemoryStore<array<string, Principal>>
 *
 * @api
 */
final class InMemoryPrincipalStore implements PrincipalStore, InMemoryStore
{
    /** @var array<string, Principal> By ID */
    private array $principals = [];

    public function add(Principal $principal): void
    {
        $id = (string) $principal->id;

        if (isset($this->principals[$id])) {
            throw new StorageException(\sprintf('A principal with the ID %s exists.', $id));
        }

        $this->principals[$id] = $principal;
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        $principal = $this->principals[(string) $id] ?? null;

        return null !== $principal && $principal->realm->equals($realm) ? $principal : null;
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        $id = (string) $before->id;

        if ($this->get($before->realm, $before->id)?->statusVersion !== $before->statusVersion) {
            return false;
        }

        $this->principals[$id] = $after;

        return true;
    }

    public function snapshot(): mixed
    {
        return $this->principals;
    }

    public function restore(mixed $snapshot): void
    {
        $this->principals = $snapshot;
    }
}
