<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Policy\Attribute\AttributeStore;

/**
 * Security attributes in the database: one row per principal and attribute, the value as JSON.
 *
 * @api
 */
final readonly class DbalAttributeStore implements AttributeStore
{
    public const string TABLE = 'principal_attribute';

    public function __construct(
        private Database $database,
    ) {
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $attributes = [];

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT name, value FROM %s WHERE realm = ? AND principal_id = ? ORDER BY name', $this->table()), [$realm->name, $principalId->uuid->toRfc4122()]) as $row) {
            $attributes[Row::string($row, 'name')] = self::value(json_decode(Row::string($row, 'value'), true, flags: \JSON_THROW_ON_ERROR), Row::string($row, 'name'));
        }

        return $attributes;
    }

    public function set(Realm $realm, PrincipalId $principalId, string $name, string|int|bool|array $value): void
    {
        $this->remove($realm, $principalId, $name);
        $this->database->connection->insert($this->table(), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122(), 'name' => $name, 'value' => json_encode($value, \JSON_THROW_ON_ERROR)]);
    }

    public function remove(Realm $realm, PrincipalId $principalId, string $name): bool
    {
        return 0 < $this->database->connection->delete($this->table(), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122(), 'name' => $name]);
    }

    public function forget(Realm $realm, PrincipalId $principalId): void
    {
        $this->database->connection->delete($this->table(), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122()]);
    }

    /**
     * @return string|int|bool|list<string>
     */
    private static function value(mixed $value, string $name): string|int|bool|array
    {
        if (\is_string($value) || \is_int($value) || \is_bool($value)) {
            return $value;
        }

        $strings = [];

        foreach (\is_array($value) && array_is_list($value) ? $value : [null] as $item) {
            $strings[] = \is_string($item) ? $item : throw new StorageException(\sprintf('The attribute "%s" holds something that isn\'t a value.', $name));
        }

        return $strings;
    }

    private function table(): string
    {
        return $this->database->table(self::TABLE);
    }
}
