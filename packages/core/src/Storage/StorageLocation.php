<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Where YorvoID's tables live in the application's database: an optional PostgreSQL schema and a prefix
 * for table and index names (PLT-022). YorvoID creates and uses only tables of its location, so an
 * application can give it a schema of its own, share one with its own tables, or both (Quire: schema
 * "quire", prefix "yorvoid_").
 *
 * @api
 */
final readonly class StorageLocation
{
    public const string DEFAULT_PREFIX = 'yorvoid_';

    /** Leaves room for YorvoID's longest names within PostgreSQL's 63-character limit */
    public const int MAX_PREFIX_LENGTH = 20;

    /** PostgreSQL's limit; MySQL's is 64 */
    public const int MAX_NAME_LENGTH = 63;

    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/D';

    public string $prefix;

    /** @var non-empty-string|null */
    public ?string $schema;

    public function __construct(string $prefix = self::DEFAULT_PREFIX, ?string $schema = null)
    {
        if ('' !== $prefix && (1 !== preg_match(self::IDENTIFIER, $prefix) || \strlen($prefix) > self::MAX_PREFIX_LENGTH)) {
            throw new IntegratorError(\sprintf('The table prefix "%s" is invalid.', $prefix), \sprintf('Use up to %d lowercase letters, digits and underscores, not starting with a digit, e.g. "yorvoid_"; or no prefix.', self::MAX_PREFIX_LENGTH), 'concepts/storage.md#where-the-tables-live');
        }

        if (null !== $schema && (1 !== preg_match(self::IDENTIFIER, $schema) || \strlen($schema) > self::MAX_NAME_LENGTH || str_starts_with($schema, 'pg_'))) {
            throw new IntegratorError(\sprintf('The schema name "%s" is invalid.', $schema), 'Use lowercase letters, digits and underscores, not starting with a digit or "pg_", e.g. "yorvoid" or "quire".', 'concepts/storage.md#where-the-tables-live');
        }

        $this->prefix = $prefix;
        $this->schema = $schema;
    }

    /**
     * The unquoted name of one of YorvoID's tables, indexes or constraints, e.g. "yorvoid_principal".
     *
     * @return non-empty-string
     */
    public function name(string $name): string
    {
        if ('' === $name || 1 !== preg_match(self::IDENTIFIER, $name)) {
            throw new IntegratorError(\sprintf('"%s" is not a valid table or index name.', $name), 'Use lowercase letters, digits and underscores.', 'concepts/storage.md#where-the-tables-live');
        }

        $full = $this->prefix.$name;

        if (\strlen($full) > self::MAX_NAME_LENGTH) {
            throw new IntegratorError(\sprintf('The name "%s" is longer than %d characters.', $full, self::MAX_NAME_LENGTH), 'Use a shorter table prefix.', 'concepts/storage.md#where-the-tables-live');
        }

        return $full;
    }

    /**
     * Whether a table or sequence of the database belongs to YorvoID, for the application's schema tools
     * to leave alone (PLT-023): pass it to Doctrine's schema assets filter. Names may be qualified with
     * their schema ("quire.yorvoid_principal").
     *
     * Without a prefix, only a schema of YorvoID's own can tell its tables apart.
     */
    public function owns(string $assetName): bool
    {
        $schema = null;
        $name = $assetName;

        if (str_contains($assetName, '.')) {
            [$schema, $name] = explode('.', $assetName, 2);
        }

        if (null !== $this->schema && null !== $schema && $schema !== $this->schema) {
            return false;
        }

        if ('' === $this->prefix) {
            return null !== $this->schema && $schema === $this->schema;
        }

        return str_starts_with($name, $this->prefix);
    }
}
