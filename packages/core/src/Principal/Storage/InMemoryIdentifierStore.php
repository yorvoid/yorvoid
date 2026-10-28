<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Storage;

use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * @implements InMemoryStore<array<string, array<string, array<string, Identifier>>>>
 *
 * @api
 */
final class InMemoryIdentifierStore implements IdentifierStore, InMemoryStore
{
    /** @var array<string, array<string, array<string, Identifier>>> By realm, kind and normalized form */
    private array $identifiers = [];

    public function add(Identifier $identifier): bool
    {
        $realm = $identifier->realm->name;
        $kind = $identifier->kind->value;

        if (isset($this->identifiers[$realm][$kind][$identifier->normalized]) || isset($this->of($identifier->realm, $identifier->principalId)[$kind])) {
            return false;
        }

        $this->identifiers[$realm][$kind][$identifier->normalized] = $identifier;

        return true;
    }

    public function remove(Realm $realm, PrincipalId $principalId, IdentifierKind $kind): void
    {
        $identifier = $this->of($realm, $principalId)[$kind->value] ?? null;

        if (null !== $identifier) {
            unset($this->identifiers[$realm->name][$kind->value][$identifier->normalized]);
        }
    }

    public function removeAll(Realm $realm, PrincipalId $principalId): void
    {
        foreach (IdentifierKind::cases() as $kind) {
            $this->remove($realm, $principalId, $kind);
        }
    }

    public function find(Realm $realm, IdentifierKind $kind, string $normalized): ?Identifier
    {
        return $this->identifiers[$realm->name][$kind->value][$normalized] ?? null;
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $identifiers = [];

        foreach (IdentifierKind::cases() as $kind) {
            foreach ($this->identifiers[$realm->name][$kind->value] ?? [] as $identifier) {
                if ($identifier->principalId->equals($principalId)) {
                    $identifiers[$kind->value] = $identifier;
                }
            }
        }

        return $identifiers;
    }

    public function snapshot(): mixed
    {
        return $this->identifiers;
    }

    public function restore(mixed $snapshot): void
    {
        $this->identifiers = $snapshot;
    }
}
