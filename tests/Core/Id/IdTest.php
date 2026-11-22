<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Id;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\Id;

/**
 * An ID is a UUID's canonical text: written in any of a UUID's forms, it reads back the same.
 */
final class IdTest extends TestCase
{
    public function testAnIdIsItsCanonicalText(): void
    {
        $id = Id::fromString('019AA0C4-6F3E-7C2A-9B1D-3E5F7A9C0B2D');

        self::assertSame('019aa0c4-6f3e-7c2a-9b1d-3e5f7a9c0b2d', $id->toString());
        self::assertSame('019aa0c4-6f3e-7c2a-9b1d-3e5f7a9c0b2d', (string) $id);
        self::assertTrue($id->equals(Id::fromString('019aa0c4-6f3e-7c2a-9b1d-3e5f7a9c0b2d')));
        self::assertFalse($id->equals(Id::fromString('019aa0c4-6f3e-7c2a-9b1d-3e5f7a9c0b2e')));
    }

    public function testAnythingElseIsNoId(): void
    {
        self::assertFalse(Id::isValid('not an id'));
        self::assertTrue(Id::isValid('019aa0c4-6f3e-7c2a-9b1d-3e5f7a9c0b2d'));
        $this->expectException(IntegratorError::class);
        Id::fromString('019aa0c4-6f3e-7c2a-9b1d');
    }
}
