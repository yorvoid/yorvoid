<?php

declare(strict_types=1);

namespace YorvoID\Tests\Properties;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Secret;

final class SecretPropertiesTest extends TestCase
{
    use BlackBox;

    public function testEqualsExactlyTheSameBytes(): void
    {
        $bytes = Set::strings()->madeOf(Set::integers()->between(0, 255)->map(static fn (int $code): string => \chr($code & 255)))->between(1, 40);

        self::forAll($bytes, Set::either($bytes, Set::of(null)))->then(static function (string $value, ?string $other): void {
            $other ??= $value;

            self::assertSame($value === $other, Secret::fromString($value)->equals($other));
            self::assertSame($value === $other, Secret::fromString($value)->equals(Secret::fromString($other)));
        });
    }
}
