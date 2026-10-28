<?php

declare(strict_types=1);

namespace YorvoID\Tests\Migrations;

use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The packages' migrations create exactly the schema below, on every database: the specification of the
 * tables, written out so that a change to a migration is a deliberate change here too.
 */
final class SchemaTest extends DatabaseTestCase
{
    /**
     * Column => [type, length, nullable]; length null where it doesn't apply.
     */
    private const array COLUMNS = [
        'principal' => [
            'id' => ['guid', null, false],
            'realm' => ['string', 63, false],
            'kind' => ['string', 16, false],
            'status' => ['string', 16, false],
            'created_at' => ['datetimetz', null, false],
            'updated_at' => ['datetimetz', null, false],
            'status_changed_at' => ['datetimetz', null, false],
            'status_version' => ['integer', null, false],
        ],
        'principal_identifier' => [
            'realm' => ['string', 63, false],
            'kind' => ['string', 16, false],
            'normalized' => ['string', 255, false],
            'value' => ['string', 255, false],
            'principal_id' => ['guid', null, false],
            'created_at' => ['datetimetz', null, false],
            'verified_at' => ['datetimetz', null, true],
        ],
        'security_log' => [
            'realm' => ['string', 63, false],
            'sequence' => ['bigint', null, false],
            'id' => ['guid', null, false],
            'recorded_at' => ['datetimetz', null, false],
            'action' => ['string', 191, false],
            'outcome' => ['string', 16, false],
            'origin_kind' => ['string', 16, false],
            'origin_detail' => ['string', 255, true],
            'correlation_id' => ['string', 128, false],
            'actor' => ['guid', null, true],
            'subject_type' => ['string', 63, true],
            'subject_id' => ['string', 191, true],
            'details' => ['json', null, false],
            'algorithm' => ['string', 16, false],
            'previous_hash' => ['string', 128, false],
            'hash' => ['string', 128, false],
        ],
        'security_log_head' => [
            'realm' => ['string', 63, false],
            'sequence' => ['bigint', null, false],
            'hash' => ['string', 128, true],
        ],
        'authentication_session' => [
            'id' => ['guid', null, false],
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'principal_status_version' => ['integer', null, false],
            'token_hash' => ['string', 64, false],
            'authentication' => ['json', null, false],
            'started_at' => ['datetimetz', null, false],
            'last_seen_at' => ['datetimetz', null, false],
            'expires_at' => ['datetimetz', null, false],
            'idle_expires_at' => ['datetimetz', null, false],
            'device' => ['string', 255, true],
            'address' => ['string', 45, true],
            'ended_at' => ['datetimetz', null, true],
            'end_reason' => ['string', 32, true],
        ],
        'one_time_token' => [
            'selector' => ['string', 32, false],
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'purpose' => ['string', 64, false],
            'verifier_hash' => ['string', 64, false],
            'email' => ['string', 255, true],
            'created_at' => ['datetimetz', null, false],
            'expires_at' => ['datetimetz', null, false],
        ],
        'credential' => [
            'id' => ['guid', null, false],
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'kind' => ['string', 32, false],
            'label' => ['string', 64, false],
            'created_at' => ['datetimetz', null, false],
            'last_used_at' => ['datetimetz', null, true],
            'status' => ['string', 16, false],
        ],
        'passkey' => [
            'credential_id' => ['guid', null, false],
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'webauthn_id_hash' => ['string', 64, false],
            'webauthn_id' => ['text', null, false],
            'public_key' => ['text', null, false],
            'user_handle' => ['string', 86, false],
            'sign_count' => ['bigint', null, false],
            'transports' => ['json', null, false],
            'aaguid' => ['guid', null, false],
            'backup_eligible' => ['boolean', null, false],
            'backed_up' => ['boolean', null, false],
            'user_verification_initialized' => ['boolean', null, false],
            'attestation_type' => ['string', 32, false],
        ],
        'passkey_ceremony' => [
            'id' => ['guid', null, false],
            'realm' => ['string', 63, false],
            'kind' => ['string', 16, false],
            'options' => ['text', null, false],
            'session_id' => ['guid', null, true],
            'operation' => ['string', 191, true],
            'expires_at' => ['datetimetz', null, false],
        ],
        'realm_setup' => [
            'realm' => ['string', 63, false],
            'code_hash' => ['string', 64, false],
            'created_at' => ['datetimetz', null, false],
            'expires_at' => ['datetimetz', null, false],
            'claimed_at' => ['datetimetz', null, true],
            'claimed_by' => ['guid', null, true],
        ],
        'recovery_code' => [
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'code_hash' => ['string', 64, false],
            'created_at' => ['datetimetz', null, false],
        ],
        'known_device' => [
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'cookie_hash' => ['string', 64, false],
            'first_seen_at' => ['datetimetz', null, false],
            'last_seen_at' => ['datetimetz', null, false],
        ],
        'throttle_key' => [
            'key_hash' => ['string', 64, false],
            'clear_at' => ['bigint', null, false],
        ],
        'recovery_cool_down' => [
            'realm' => ['string', 63, false],
            'principal_id' => ['guid', null, false],
            'ends_at' => ['datetimetz', null, false],
        ],
    ];

    /**
     * Index => [columns, unique]; the primary key as "primary".
     */
    private const array INDEXES = [
        'principal' => [
            'primary' => [['id'], true],
            'principal_realm' => [['realm', 'id'], false],
        ],
        'principal_identifier' => [
            'primary' => [['realm', 'kind', 'normalized'], true],
            'principal_identifier_principal' => [['principal_id', 'kind'], true],
        ],
        'security_log' => [
            'primary' => [['realm', 'sequence'], true],
            'security_log_id' => [['id'], true],
            'security_log_subject' => [['realm', 'subject_type', 'subject_id'], false],
            'security_log_actor' => [['realm', 'actor'], false],
        ],
        'security_log_head' => [
            'primary' => [['realm'], true],
        ],
        'authentication_session' => [
            'primary' => [['id'], true],
            'authentication_session_token' => [['token_hash'], true],
            'authentication_session_principal' => [['realm', 'principal_id'], false],
        ],
        'one_time_token' => [
            'primary' => [['selector'], true],
            'one_time_token_principal' => [['realm', 'principal_id', 'purpose'], false],
            'one_time_token_expiry' => [['expires_at'], false],
        ],
        'credential' => [
            'primary' => [['id'], true],
            'credential_principal' => [['realm', 'principal_id'], false],
        ],
        'passkey' => [
            'primary' => [['credential_id'], true],
            'passkey_webauthn_id' => [['realm', 'webauthn_id_hash'], true],
            'passkey_principal' => [['realm', 'principal_id'], false],
        ],
        'passkey_ceremony' => [
            'primary' => [['id'], true],
            'passkey_ceremony_expiry' => [['expires_at'], false],
        ],
        'realm_setup' => [
            'primary' => [['realm'], true],
        ],
        'recovery_code' => [
            'primary' => [['realm', 'principal_id', 'code_hash'], true],
        ],
        'known_device' => [
            'primary' => [['realm', 'principal_id', 'cookie_hash'], true],
            'known_device_last_seen' => [['last_seen_at'], false],
        ],
        'throttle_key' => [
            'primary' => [['key_hash'], true],
            'throttle_key_clear' => [['clear_at'], false],
        ],
        'recovery_cool_down' => [
            'primary' => [['realm', 'principal_id'], true],
            'recovery_cool_down_end' => [['ends_at'], false],
        ],
    ];

    #[DataProvider('platforms')]
    public function testTheMigrationsCreateTheSpecifiedSchema(string $platform): void
    {
        $database = $this->stack($platform)->database;
        \assert($database instanceof Database);

        foreach (self::COLUMNS as $name => $columns) {
            $table = $this->table($database, $name);
            $actual = [];

            foreach ($table->getColumns() as $column) {
                $type = $column->getTypeName();
                $actual[$column->getObjectName()->getIdentifier()->getValue()] = [$type, 'string' === $type ? $column->getLength() : null, !$column->getNotnull()];
            }

            self::assertSame('sqlite' === $platform ? self::onSqlite($columns) : $columns, $actual, \sprintf('The columns of "%s" differ.', $name));
            $expected = self::INDEXES[$name];
            $indexes = $this->indexes($database, $table);
            ksort($expected);
            ksort($indexes);
            self::assertSame($expected, $indexes, \sprintf('The indexes of "%s" differ.', $name));
        }
    }

    /**
     * SQLite has no UUID, time zone or JSON types: UUIDs are stored as 36 characters, times as text
     * that DBAL reads back as plain datetimes (YorvoID stores times in UTC, so nothing is lost), and
     * JSON as text.
     *
     * @param array<string, array{string, ?int, bool}> $columns
     *
     * @return array<string, array{string, ?int, bool}>
     */
    private static function onSqlite(array $columns): array
    {
        return array_map(static fn (array $column): array => match ($column[0]) {
            'guid' => ['string', 36, $column[2]],
            'datetimetz' => ['datetime', null, $column[2]],
            'json' => ['text', null, $column[2]],
            default => $column,
        }, $columns);
    }

    private function table(Database $database, string $name): Table
    {
        $manager = $database->connection->createSchemaManager();
        $table = $database->location->name($name);

        return null === $database->location->schema ? $manager->introspectTableByUnquotedName($table) : $manager->introspectTableByUnquotedName($table, $database->location->schema);
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    private function indexes(Database $database, Table $table): array
    {
        $indexes = [];
        $primary = $table->getPrimaryKeyConstraint();
        \assert(null !== $primary);
        $indexes['primary'] = [array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $primary->getColumnNames()), true];

        foreach ($table->getIndexes() as $index) {
            $columns = array_map(static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(), $index->getIndexedColumns());
            $name = $index->getObjectName()->getIdentifier()->getValue();

            // Some databases list the primary key's own index too.
            if ($columns === $indexes['primary'][0] && !str_starts_with($name, $database->location->prefix.$table->getObjectName()->getUnqualifiedName()->getValue().'_')) {
                continue;
            }

            $indexes[substr($name, \strlen($database->location->prefix))] = [$columns, IndexType::UNIQUE === $index->getType()];
        }

        return $indexes;
    }
}
