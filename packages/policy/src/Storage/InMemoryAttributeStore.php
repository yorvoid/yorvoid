<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Policy\Attribute\AttributeStore;

/**
 * @implements InMemoryStore<array<string, array<string, string|int|bool|list<string>>>>
 *
 * @api
 */
final class InMemoryAttributeStore implements AttributeStore, InMemoryStore
{
    /** @var array<string, array<string, string|int|bool|list<string>>> By realm and principal */
    private array $attributes = [];

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $attributes = $this->attributes[self::key($realm, $principalId)] ?? [];
        ksort($attributes, \SORT_STRING);

        return $attributes;
    }

    public function set(Realm $realm, PrincipalId $principalId, string $name, string|int|bool|array $value): void
    {
        $this->attributes[self::key($realm, $principalId)][$name] = $value;
    }

    public function remove(Realm $realm, PrincipalId $principalId, string $name): bool
    {
        $had = isset($this->attributes[self::key($realm, $principalId)][$name]);
        unset($this->attributes[self::key($realm, $principalId)][$name]);

        return $had;
    }

    public function forget(Realm $realm, PrincipalId $principalId): void
    {
        unset($this->attributes[self::key($realm, $principalId)]);
    }

    public function snapshot(): mixed
    {
        return $this->attributes;
    }

    public function restore(mixed $snapshot): void
    {
        $this->attributes = $snapshot;
    }

    private static function key(Realm $realm, PrincipalId $principalId): string
    {
        return $realm->name."\n".$principalId->uuid->toRfc4122();
    }
}
