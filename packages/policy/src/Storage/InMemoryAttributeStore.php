<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Policy\Attribute\AttributeStore;

/**
 * @implements InMemoryStore<array<string, array<string, array<string, string|int|bool|list<string>>>>>
 *
 * @api
 */
final class InMemoryAttributeStore implements AttributeStore, InMemoryStore
{
    /** @var array<string, array<string, array<string, string|int|bool|list<string>>>> By realm, principal, name */
    private array $attributes = [];

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $attributes = $this->attributes[$realm->name][$principalId->uuid->toRfc4122()] ?? [];
        ksort($attributes, \SORT_STRING);

        return $attributes;
    }

    public function set(Realm $realm, PrincipalId $principalId, string $name, string|int|bool|array $value): void
    {
        $this->attributes[$realm->name][$principalId->uuid->toRfc4122()][$name] = $value;
    }

    public function remove(Realm $realm, PrincipalId $principalId, string $name): bool
    {
        $had = isset($this->attributes[$realm->name][$principalId->uuid->toRfc4122()][$name]);
        unset($this->attributes[$realm->name][$principalId->uuid->toRfc4122()][$name]);

        return $had;
    }

    public function forget(Realm $realm, PrincipalId $principalId): void
    {
        unset($this->attributes[$realm->name][$principalId->uuid->toRfc4122()]);
    }

    public function snapshot(): mixed
    {
        return $this->attributes;
    }

    public function restore(mixed $snapshot): void
    {
        $this->attributes = $snapshot;
    }
}
