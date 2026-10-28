<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\StorageLocation;

final class StorageLocationTest extends TestCase
{
    public function testDefaults(): void
    {
        $location = new StorageLocation();

        self::assertSame('yorvoid_', $location->prefix);
        self::assertNull($location->schema);
        self::assertSame('yorvoid_principal', $location->name('principal'));
    }

    public function testNamesGetThePrefix(): void
    {
        self::assertSame('auth_principal', new StorageLocation('auth_', 'quire')->name('principal'));
        self::assertSame('principal', new StorageLocation('')->name('principal'));
    }

    public function testNamesUpToTheLimit(): void
    {
        $location = new StorageLocation(str_repeat('p', 20));

        self::assertSame(63, \strlen($location->name(str_repeat('n', 43))));

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('longer than 63 characters');
        $location->name(str_repeat('n', 44));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Principal'];
        yield 'hyphen' => ['principal-key'];
        yield 'quote' => ['principal"; DROP TABLE x; --'];
        yield 'dot' => ['other.principal'];
        yield 'starts with a digit' => ['2fa'];
        yield 'trailing newline' => ["principal\n"];
    }

    #[DataProvider('invalidNames')]
    public function testRefusesInvalidNames(string $name): void
    {
        $this->expectException(IntegratorError::class);

        new StorageLocation()->name($name);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function invalidLocations(): iterable
    {
        yield 'prefix with uppercase' => ['YorvoID_', null];
        yield 'prefix with a hyphen' => ['yorvoid-', null];
        yield 'prefix too long' => [str_repeat('p', 21), null];
        yield 'prefix starting with a digit' => ['1_', null];
        yield 'empty schema' => ['yorvoid_', ''];
        yield 'schema with uppercase' => ['yorvoid_', 'Quire'];
        yield 'schema with a quote' => ['yorvoid_', 'quire"'];
        yield 'system schema' => ['yorvoid_', 'pg_catalog'];
        yield 'schema too long' => ['yorvoid_', str_repeat('s', 64)];
        yield 'schema with a trailing newline' => ['yorvoid_', "quire\n"];
    }

    #[DataProvider('invalidLocations')]
    public function testRefusesInvalidLocations(string $prefix, ?string $schema): void
    {
        $this->expectException(IntegratorError::class);

        new StorageLocation($prefix, $schema);
    }

    public function testAcceptsTheLongestSchemaAndPrefix(): void
    {
        $location = new StorageLocation(str_repeat('p', 20), str_repeat('s', 63));

        self::assertSame(str_repeat('s', 63), $location->schema);
    }

    /**
     * @return iterable<string, array{string, ?string, string, bool}>
     */
    public static function assets(): iterable
    {
        yield 'own table, unqualified' => ['yorvoid_', null, 'yorvoid_principal', true];
        yield 'own table in any schema without one configured' => ['yorvoid_', null, 'public.yorvoid_principal', true];
        yield 'application table' => ['yorvoid_', null, 'app_user', false];
        yield 'own table in own schema' => ['yorvoid_', 'quire', 'quire.yorvoid_principal', true];
        yield 'own table, unqualified, with a schema configured' => ['yorvoid_', 'quire', 'yorvoid_principal', true];
        yield 'prefixed table in another schema' => ['yorvoid_', 'quire', 'public.yorvoid_principal', false];
        yield 'application table in own schema' => ['yorvoid_', 'quire', 'quire.content', false];
        yield 'no prefix, own schema' => ['', 'yorvoid', 'yorvoid.principal', true];
        yield 'no prefix, other schema' => ['', 'yorvoid', 'public.principal', false];
        yield 'no prefix, unqualified' => ['', 'yorvoid', 'principal', false];
        yield 'no prefix, no schema' => ['', null, 'principal', false];
    }

    #[DataProvider('assets')]
    public function testTellsItsTablesFromTheApplications(string $prefix, ?string $schema, string $asset, bool $owned): void
    {
        self::assertSame($owned, new StorageLocation($prefix, $schema)->owns($asset));
    }
}
