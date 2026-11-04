<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\IdentifierRenormalization;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Identifiers stored by 0.1, whose email addresses were compared in lowercase in every script, are
 * compared by this version's rules after the renormalization (FUZZ-12).
 */
final class IdentifierRenormalizationTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testBringsStoredFormsUpToTheseRules(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);

        // As 0.1 stored them: mb_strtolower() of the whole address.
        $this->stored($stack->identifiers, $database, 'acme', 'ana@example.com', 'ana@example.com');
        $this->stored($stack->identifiers, $database, 'acme', 'Ána@Пример.рф', 'ána@пример.рф');
        $this->stored($stack->identifiers, $database, 'acme', "Jose\u{301}@example.com", "jose\u{301}@example.com");
        $this->stored($stack->identifiers, $database, 'acme', "jos\u{E9}@example.com", "jos\u{E9}@example.com");
        $this->stored($stack->identifiers, $database, 'acme', 'Bob', 'BOB', IdentifierKind::Username);
        // Another realm: the same address there is someone else's, and changes on its own.
        $this->stored($stack->identifiers, $database, 'globex', 'Ána@Пример.рф', 'ána@пример.рф');
        $this->stored($stack->identifiers, $database, 'globex', "Jose\u{301}@example.com", "jose\u{301}@example.com");

        $renormalization = new IdentifierRenormalization($database);
        $planned = $renormalization->run(dryRun: true);

        // In the order they were stored, which is the same here: any order.
        self::assertEqualsCanonicalizing([
            ['realm' => 'acme', 'from' => 'ána@пример.рф', 'to' => 'Ána@xn--e1afmkfd.xn--p1ai'],
            ['realm' => 'globex', 'from' => 'ána@пример.рф', 'to' => 'Ána@xn--e1afmkfd.xn--p1ai'],
            ['realm' => 'globex', 'from' => "jose\u{301}@example.com", 'to' => "jos\u{E9}@example.com"],
        ], $planned['changed']);
        self::assertSame([['realm' => 'acme', 'value' => "Jose\u{301}@example.com", 'with' => "jos\u{E9}@example.com"]], $planned['conflicts'], 'The same address in two Unicode forms is two people\'s: one decides.');
        self::assertSame([], $planned['invalid']);
        self::assertNull($stack->identifiers->find(new Realm('acme'), IdentifierKind::Email, 'Ána@xn--e1afmkfd.xn--p1ai'), 'A dry run changed something.');

        self::assertEquals($planned, $renormalization->run());

        foreach (['acme', 'globex'] as $realm) {
            self::assertSame('Ána@Пример.рф', $stack->identifiers->find(new Realm($realm), IdentifierKind::Email, IdentifierKind::Email->normalize('Ána@ПРИМЕР.рф'))?->value);
        }

        self::assertSame("Jose\u{301}@example.com", $stack->identifiers->find(new Realm('globex'), IdentifierKind::Email, "jos\u{E9}@example.com")?->value);
        self::assertSame("jos\u{E9}@example.com", $stack->identifiers->find(new Realm('acme'), IdentifierKind::Email, "jos\u{E9}@example.com")?->value, 'The conflict was settled for someone.');
        self::assertNotNull($stack->identifiers->find(new Realm('acme'), IdentifierKind::Email, 'ana@example.com'));
        self::assertNotNull($stack->identifiers->find(new Realm('acme'), IdentifierKind::Username, 'BOB'), 'A username was changed.');
        self::assertSame(['changed' => [], 'conflicts' => $planned['conflicts'], 'invalid' => []], $renormalization->run(), 'A second run changed more.');
    }

    public function testReportsWhatTheseRulesRefuseAndGoesOn(): void
    {
        $stack = $this->stack('sqlite');
        $database = $stack->database;
        \assert($database instanceof Database);
        $this->stored($stack->identifiers, $database, 'acme', 'ana@example.com', "ana@\u{301}example.com", value: "ana@\u{301}example.com");
        $this->stored($stack->identifiers, $database, 'acme', 'Ána@Пример.рф', 'ána@пример.рф');

        $report = new IdentifierRenormalization($database)->run();

        self::assertSame([['realm' => 'acme', 'value' => "ana@\u{301}example.com"]], $report['invalid']);
        self::assertCount(1, $report['changed'], 'An invalid address stopped the others.');
    }

    /**
     * An identifier as an earlier version stored it: its compared form, and its value if it no longer passes.
     */
    private function stored(IdentifierStore $identifiers, Database $database, string $realm, string $valid, string $old, IdentifierKind $kind = IdentifierKind::Email, ?string $value = null): void
    {
        $identifier = new Identifier(new Realm($realm), new PrincipalId(new UuidV7()), $kind, $valid, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
        self::assertTrue($identifiers->add($identifier));
        $database->connection->update($database->table(DbalIdentifierStore::TABLE), ['normalized' => $old, 'value' => $value ?? $valid], ['realm' => $realm, 'kind' => $kind->value, 'normalized' => $identifier->normalized]);
    }
}
