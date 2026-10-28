<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Secret;

final class SecretTest extends TestCase
{
    public function testComparesWithSecretsAndStrings(): void
    {
        $secret = Secret::fromString('correct horse');

        self::assertTrue($secret->equals('correct horse'));
        self::assertTrue($secret->equals(Secret::fromString('correct horse')));
        self::assertFalse($secret->equals('correct horsf'));
        self::assertFalse($secret->equals('correct hors'));
        self::assertFalse($secret->equals(Secret::fromString('battery staple')));
        self::assertSame('correct horse', $secret->reveal());
    }

    public function testRandomSecretsHaveTheRequestedLengthAndDiffer(): void
    {
        self::assertSame(32, \strlen(Secret::random()->reveal()));
        self::assertSame(16, \strlen(Secret::random(16)->reveal()));
        self::assertSame(64, \strlen(Secret::random(64)->reveal()));
        self::assertFalse(Secret::random()->equals(Secret::random()));
    }

    public function testRefusesShortRandomSecrets(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('at least 16 bytes');

        Secret::random(15);
    }

    public function testRefusesAnEmptySecret(): void
    {
        $this->expectException(IntegratorError::class);

        Secret::fromString('');
    }

    public function testNeverShowsItsValue(): void
    {
        $secret = Secret::fromString('hunter2-correct-horse');

        self::assertStringNotContainsString('hunter2', print_r($secret, true));
        self::assertSame(['value' => '[redacted]'], $secret->__debugInfo());
        self::assertSame('{}', json_encode($secret));
    }

    public function testCantBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize(Secret::fromString('hunter2'));
    }

    public function testCantBeUnserialized(): void
    {
        $this->expectException(\LogicException::class);

        unserialize(\sprintf('O:%d:"%s":1:{s:5:"value";s:7:"hunter2";}', \strlen(Secret::class), Secret::class));
    }

    public function testStaysOutOfStackTraces(): void
    {
        try {
            (static function (Secret $secret): never {
                throw new \RuntimeException('failed');
            })(Secret::fromString('hunter2'));
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('hunter2', $exception->getTraceAsString());
        }
    }
}
