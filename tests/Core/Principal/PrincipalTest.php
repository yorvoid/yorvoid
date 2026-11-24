<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\AgentProfile;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Testing\Ids;

/**
 * Only an agent has an owner and a profile, and every agent has an owner: whoever makes a principal,
 * a handler, an import or a store reading a row.
 */
final class PrincipalTest extends TestCase
{
    /**
     * @return iterable<string, array{PrincipalKind, bool, bool, bool}> Kind, owner, profile, whether it's one
     */
    public static function shapes(): iterable
    {
        yield 'an agent with an owner' => [PrincipalKind::Agent, true, false, true];
        yield 'an agent with an owner and a profile' => [PrincipalKind::Agent, true, true, true];
        yield 'an agent without an owner' => [PrincipalKind::Agent, false, true, false];

        foreach ([PrincipalKind::Human, PrincipalKind::Service] as $kind) {
            yield $kind->value.' alone' => [$kind, false, false, true];
            yield $kind->value.' with an owner' => [$kind, true, false, false];
            yield $kind->value.' with a profile' => [$kind, false, true, false];
        }
    }

    #[DataProvider('shapes')]
    public function testOnlyAnAgentHasAnOwnerAndEveryAgentHasOne(PrincipalKind $kind, bool $owner, bool $profile, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(IntegratorError::class);
            $this->expectExceptionMessage((PrincipalKind::Agent === $kind ? 'is an agent without an owner' : 'is not an agent, with an owner or a profile').': only an agent has an owner and a profile, and every agent has an owner.');
        }

        $now = new \DateTimeImmutable('2026-11-23 18:00:00');
        $principal = new Principal(new PrincipalId(Ids::next()), new Realm('acme'), $kind, PrincipalStatus::Active, $now, $now, $now, 1, $owner ? new PrincipalId(Ids::next()) : null, $profile ? new AgentProfile('Assistant') : null);

        self::assertSame($kind, $principal->kind);
    }
}
