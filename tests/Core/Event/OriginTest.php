<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;

final class OriginTest extends TestCase
{
    public function testKeepsWhatTheApplicationGives(): void
    {
        $origin = new Origin(OriginKind::Console, 'yorvoid:principal:suspend', 'req-42:a.b_c');

        self::assertSame(OriginKind::Console, $origin->kind);
        self::assertSame('yorvoid:principal:suspend', $origin->detail);
        self::assertSame('req-42:a.b_c', $origin->correlationId);
    }

    public function testCreatesARandomCorrelationIdWhenNoneIsGiven(): void
    {
        $first = new Origin(OriginKind::Web);
        $second = new Origin(OriginKind::Web);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->correlationId);
        self::assertNotSame($first->correlationId, $second->correlationId);
        self::assertNull($first->detail);
    }

    public function testAcceptsTheLongestCorrelationId(): void
    {
        self::assertSame(128, \strlen(new Origin(OriginKind::Job, null, str_repeat('a', 128))->correlationId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCorrelationIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'space' => ['req 42'];
        yield 'slash' => ['a/b'];
        yield 'newline, to forge log lines' => ["req-42\nforged"];
        yield 'trailing newline' => ["req-42\n"];
    }

    #[DataProvider('invalidCorrelationIds')]
    public function testRefusesInvalidCorrelationIds(string $correlationId): void
    {
        $this->expectException(IntegratorError::class);

        new Origin(OriginKind::Api, null, $correlationId);
    }
}
