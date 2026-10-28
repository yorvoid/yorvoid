<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Two administrators suspend the last two owners at the same moment, each in their own process: a guard
 * rule keeping one owner active must refuse one of them (IDN-016). Checking each change on its own isn't
 * enough: each sees the other owner still active, unless changes in the realm wait for each other.
 */
final class GuardRuleConcurrencyTest extends DatabaseTestCase
{
    public function testTwoConcurrentChangesCantBothBreakTheRule(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $principals = new Principals($stack->principals, $stack->identifiers, new SecurityLog($stack->log, $stack->transactions), $stack->transactions);
        $owners = [
            $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console)),
            $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console)),
        ];
        $group = implode(',', array_map(static fn (Principal $owner): string => (string) $owner->id, $owners));
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $processes = [];

        foreach ($owners as $owner) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/suspend-principal.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, (string) $owner->id, $group, $start],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        touch($start);
        $results = [];

        try {
            foreach ($processes as $i => [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                $results[] = [proc_close($process), $output];
                unset($processes[$i]);
            }
        } finally {
            unlink($start);

            foreach ($processes as [$process]) {
                proc_terminate($process);
            }
        }

        $exitCodes = array_column($results, 0);
        sort($exitCodes);
        self::assertSame([0, 3], $exitCodes, 'Expected one suspension and one refusal: '.implode(' | ', array_column($results, 1)));

        $active = array_filter($owners, static fn (Principal $owner): bool => true === $principals->get(new Realm('acme'), $owner->id)?->isActive());
        self::assertCount(1, $active);
        self::assertTrue(new SecurityLog($stack->log, $stack->transactions)->verify(new Realm('acme'))->isIntact());
    }
}
