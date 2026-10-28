<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;

final class RowTest extends TestCase
{
    public function testReadsWhatDriversReturn(): void
    {
        $row = ['text' => 'abc', 'none' => null, 'int' => 42, 'numeric' => '9007199254740993', 'negative' => '-7'];

        self::assertSame('abc', Row::string($row, 'text'));
        self::assertSame('abc', Row::nullableString($row, 'text'));
        self::assertNull(Row::nullableString($row, 'none'));
        self::assertNull(Row::nullableString($row, 'missing'));
        self::assertSame(42, Row::int($row, 'int'));
        self::assertSame(9007199254740993, Row::int($row, 'numeric'));
        self::assertSame(-7, Row::int($row, 'negative'));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function unexpected(): iterable
    {
        yield 'a missing string' => [static fn (): string => Row::string([], 'text'), '"text" holds null where a string was expected'];
        yield 'a number for a string' => [static fn (): string => Row::string(['text' => 5], 'text'), '"text" holds int where a string was expected'];
        yield 'a number for a nullable string' => [static fn (): ?string => Row::nullableString(['text' => 5], 'text'), 'holds int where a string or null was expected'];
        yield 'a fraction' => [static fn (): int => Row::int(['n' => '1.5'], 'n'), '"n" holds string where an integer was expected'];
        yield 'text with a number' => [static fn (): int => Row::int(['n' => '12abc'], 'n'), 'where an integer was expected'];
        yield 'a number with a newline' => [static fn (): int => Row::int(['n' => "12\n"], 'n'), 'where an integer was expected'];
        yield 'null for a number' => [static fn (): int => Row::int(['n' => null], 'n'), '"n" holds null where an integer was expected'];
        yield 'a float' => [static fn (): int => Row::int(['n' => 1.0], 'n'), '"n" holds float'];
    }

    /**
     * @param \Closure(): mixed $read
     */
    #[DataProvider('unexpected')]
    public function testRefusesWhatYorvoIDDidntWrite(\Closure $read, string $message): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($message);

        $read();
    }
}
