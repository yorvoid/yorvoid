<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;

final class RealmTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'the default' => ['default'];
        yield 'one character' => ['a'];
        yield 'a digit' => ['7'];
        yield 'with hyphens' => ['acme-corp-eu'];
        yield '63 characters' => [str_repeat('a', 63)];
    }

    #[DataProvider('validNames')]
    public function testAcceptsValidNames(string $name): void
    {
        self::assertSame($name, new Realm($name)->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Acme'];
        yield 'leading hyphen' => ['-acme'];
        yield 'trailing hyphen' => ['acme-'];
        yield '64 characters' => [str_repeat('a', 64)];
        yield 'underscore' => ['acme_corp'];
        yield 'dot' => ['acme.corp'];
        yield 'space' => ['acme corp'];
        yield 'trailing newline' => ["acme\n"];
        yield 'non-ASCII' => ['äcme'];
    }

    #[DataProvider('invalidNames')]
    public function testRefusesInvalidNamesWithAFix(string $name): void
    {
        try {
            new Realm($name);
            self::fail('The name was accepted.');
        } catch (IntegratorError $error) {
            self::assertStringContainsString('is invalid', $error->problem);
            self::assertStringContainsString('lowercase letters, digits and hyphens', $error->fix);
            self::assertStringContainsString('How to fix it:', $error->getMessage());
            self::assertStringContainsString(IntegratorError::DOCS.'concepts/realms.md', $error->getMessage());
        }
    }

    public function testTheDefaultRealm(): void
    {
        self::assertSame('default', Realm::default()->name);
        self::assertTrue(Realm::default()->equals(new Realm('default')));
        self::assertFalse(Realm::default()->equals(new Realm('other')));
        self::assertSame('default', (string) Realm::default());
    }
}
