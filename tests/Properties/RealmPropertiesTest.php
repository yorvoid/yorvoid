<?php

declare(strict_types=1);

namespace YorvoID\Tests\Properties;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;

/**
 * Realm names, against an oracle written without regular expressions: any string, including Unicode
 * and control characters, is accepted exactly when the oracle accepts it.
 */
final class RealmPropertiesTest extends TestCase
{
    use BlackBox;

    public function testAcceptsExactlyWhatTheOracleAccepts(): void
    {
        $alphabet = Set::of(...str_split('abcdefghijklmnopqrstuvwxyz0123456789-'));

        self::forAll(Set::either(
            Set::strings()->madeOf($alphabet)->between(0, 70),
            Set::strings()->unicode()->between(0, 70),
            Set::strings()->madeOf($alphabet, Set::of('_', '.', 'A', ' ', "\n", "\0", 'é'))->between(1, 66),
            // The edges: around the 63-character limit, and hyphens at either end.
            Set::strings()->madeOf(Set::strings()->chars()->lowercaseLetter())->between(61, 65),
            Set::strings()->madeOf($alphabet)->between(0, 3)->map(static fn (string $name): string => '-'.$name),
            Set::strings()->madeOf($alphabet)->between(0, 3)->map(static fn (string $name): string => $name.'-'),
        ))->then(static function (string $name): void {
            try {
                new Realm($name);
                $accepted = true;
            } catch (IntegratorError) {
                $accepted = false;
            }

            self::assertSame(self::oracle($name), $accepted, var_export($name, true));
        });
    }

    private static function oracle(string $name): bool
    {
        $length = \strlen($name);

        if ($length < 1 || $length > 63 || '-' === $name[0] || '-' === $name[$length - 1]) {
            return false;
        }

        for ($i = 0; $i < $length; ++$i) {
            $ord = \ord($name[$i]);

            if (!($ord >= 97 && $ord <= 122) && !($ord >= 48 && $ord <= 57) && 45 !== $ord) {
                return false;
            }
        }

        return true;
    }
}
