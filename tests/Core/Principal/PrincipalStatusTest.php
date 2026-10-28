<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\InvalidStatusChange;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;

final class PrincipalStatusTest extends TestCase
{
    /**
     * Every change IDN-004 allows, and no other: invited → active ⇄ suspended, and each of them → deleted.
     */
    public function testAllowsExactlyTheSpecifiedChanges(): void
    {
        $allowed = [];

        foreach (PrincipalStatus::cases() as $from) {
            foreach (PrincipalStatus::cases() as $to) {
                if ($from->canBecome($to)) {
                    $allowed[] = $from->value.' → '.$to->value;
                }
            }
        }

        self::assertSame([
            'invited → active',
            'invited → deleted',
            'active → suspended',
            'active → deleted',
            'suspended → active',
            'suspended → deleted',
        ], $allowed);
    }

    public function testAPrincipalWithAnotherStatusCountsTheChange(): void
    {
        $created = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');
        $principal = new Principal(new PrincipalId(new UuidV7()), new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Invited, $created, $created, $created);

        $active = $principal->withStatus(PrincipalStatus::Active, new \DateTimeImmutable('2026-10-28 10:00:00.9 Europe/Skopje'));

        self::assertTrue($active->isActive());
        self::assertFalse($principal->isActive());
        self::assertSame(2, $active->statusVersion);
        self::assertEquals(new \DateTimeImmutable('2026-10-28 09:00:00 UTC'), $active->statusChangedAt);
        self::assertSame('UTC', $active->statusChangedAt->getTimezone()->getName());
        self::assertEquals($active->statusChangedAt, $active->updatedAt);
        self::assertSame([$principal->id, $principal->realm, $principal->kind, $principal->createdAt], [$active->id, $active->realm, $active->kind, $active->createdAt]);
        self::assertSame(3, $active->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable())->statusVersion);
    }

    public function testRefusesAChangeItDoesntAllow(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');
        $principal = new Principal(new PrincipalId(new UuidV7()), new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Deleted, $now, $now, $now);

        $this->expectException(InvalidStatusChange::class);
        $this->expectExceptionMessage(\sprintf('The principal %s is deleted and can\'t become active.', $principal->id));

        $principal->withStatus(PrincipalStatus::Active, $now);
    }
}
