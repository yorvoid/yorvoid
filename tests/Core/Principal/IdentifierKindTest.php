<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\InvalidIdentifier;

final class IdentifierKindTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validEmails(): iterable
    {
        yield 'plain' => ['ana@example.com', 'ana@example.com'];
        yield 'one-character local part' => ['a@example.com', 'a@example.com'];
        yield 'one-character domain' => ['ana@x', 'ana@x'];
        yield 'any case' => ['Ana.Kovac@Example.COM', 'ana.kovac@example.com'];
        yield 'plus' => ['ana+yorvoid@example.com', 'ana+yorvoid@example.com'];
        yield 'beyond ASCII' => ['Ána@Пример.рф', 'ána@пример.рф'];
        yield 'one-label domain' => ['root@localhost', 'root@localhost'];
        yield 'longest local part' => [str_repeat('a', 64).'@example.com', str_repeat('a', 64).'@example.com'];
        yield 'longest address' => [str_repeat('a', 64).'@'.str_repeat('b', 185).'.com', str_repeat('a', 64).'@'.str_repeat('b', 185).'.com'];
    }

    #[DataProvider('validEmails')]
    public function testAcceptsAndLowercasesEmailAddresses(string $email, string $normalized): void
    {
        self::assertSame($normalized, IdentifierKind::Email->normalize($email));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'empty' => [''];
        yield 'no @' => ['ana.example.com'];
        yield 'nothing before @' => ['@example.com'];
        yield 'nothing after @' => ['ana@'];
        yield 'two @' => ['ana@bob@example.com'];
        yield 'space' => ['ana kovac@example.com'];
        yield 'leading space' => [' ana@example.com'];
        yield 'trailing newline' => ["ana@example.com\n"];
        yield 'tab' => ["ana@exam\tple.com"];
        yield 'control character' => ["ana\x00@example.com"];
        yield 'invisible format character' => ["ana\u{200B}@example.com"];
        yield 'invalid UTF-8' => ["an\xC3@example.com"];
        yield 'local part too long' => [str_repeat('a', 65).'@example.com'];
        yield 'address too long' => [str_repeat('a', 64).'@'.str_repeat('b', 186).'.com'];
        yield 'longer in lowercase' => [str_repeat('a', 64).'@'.str_repeat('b', 174).str_repeat('İ', 5).'.com'];
        yield 'domain starts with a dot' => ['ana@.example.com'];
        yield 'domain ends with a dot' => ['ana@example.com.'];
        yield 'two dots in the domain' => ['ana@example..com'];
    }

    #[DataProvider('invalidEmails')]
    public function testRefusesWhatIsntAnEmailAddress(string $email): void
    {
        try {
            IdentifierKind::Email->normalize($email);
            self::fail(\sprintf('"%s" was accepted.', $email));
        } catch (InvalidIdentifier $exception) {
            self::assertSame(IdentifierKind::Email, $exception->kind);
            // What was entered isn't repeated: it may be a typo of someone's password.
            self::assertContains($exception->getMessage(), ['An email address looks like name@example.com, with no spaces.', 'An email address has at most 254 characters.']);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validUsernames(): iterable
    {
        yield 'one character' => ['a', 'a'];
        yield 'any case' => ['Ana', 'ana'];
        yield 'all allowed characters' => ['ana.kovac_2-x', 'ana.kovac_2-x'];
        yield 'digits only' => ['007', '007'];
        yield 'longest' => [str_repeat('a', 64), str_repeat('a', 64)];
    }

    #[DataProvider('validUsernames')]
    public function testAcceptsAndLowercasesUsernames(string $username, string $normalized): void
    {
        self::assertSame($normalized, IdentifierKind::Username->normalize($username));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernames(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'starts with a dot' => ['.ana'];
        yield 'ends with a hyphen' => ['ana-'];
        yield 'starts with an underscore' => ['_ana'];
        yield 'space' => ['ana kovac'];
        yield 'at' => ['ana@example'];
        yield 'Cyrillic a, which looks like a Latin one' => ['аna'];
        yield 'accented' => ['ána'];
        yield 'trailing newline' => ["ana\n"];
    }

    #[DataProvider('invalidUsernames')]
    public function testRefusesWhatIsntAUsername(string $username): void
    {
        $this->expectException(InvalidIdentifier::class);
        $this->expectExceptionMessage('A username has 1 to 64 letters');

        IdentifierKind::Username->normalize($username);
    }
}
