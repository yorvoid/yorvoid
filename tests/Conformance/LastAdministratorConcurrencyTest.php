<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\GuardRule;
use YorvoID\Core\Principal\GuardRuleViolated;
use YorvoID\Core\Principal\PrincipalChange;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;

/**
 * A guard rule that keeps an active administrator reads roles, which a change of status doesn't make:
 * while another process takes administrator B's role and hasn't committed, suspending administrator A
 * waits for it, on the realm's lock, and then sees it: under READ COMMITTED by reading what was
 * committed, under REPEATABLE READ by conflicting and running again. The suspension is refused: the
 * realm never ends with no active administrator because the two changes didn't see each other.
 */
final class LastAdministratorConcurrencyTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function isolations(): iterable
    {
        yield 'read committed' => ['READ COMMITTED'];
        yield 'repeatable read' => ['REPEATABLE READ'];
    }

    #[DataProvider('isolations')]
    public function testSuspendingAnAdministratorSeesARoleTakenAtTheSameMoment(string $isolation): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $realm = new Realm('acme');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, guardRules: [new KeepAnAdministrator($stack->principals, $stack->policy)]);
        [$a, $b] = [
            Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Console)),
            Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Console)),
        ];
        $stack->transactions->run(static function () use ($stack, $realm, $a, $b): void {
            $stack->transactions->lockRealm($realm);
            $stack->policy->saveRole($realm, 'admin', 'Administrator');
            $stack->policy->assign(new RoleAssignment($realm, $a->id, 'admin', new \DateTimeImmutable()));
            $stack->policy->assign(new RoleAssignment($realm, $b->id, 'admin', new \DateTimeImmutable()));
        });
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../Support/revoke-role.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, (string) $b->id, 'admin', $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $database->connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL '.$isolation);
        $refused = null;
        $conflicts = 0;

        try {
            touch($start);
            // Inside the revocation's transaction: B's role is taken, not yet committed.
            self::waitFor($start.'.ready');

            for ($attempt = 1; $attempt <= 2 && null === $refused; ++$attempt) {
                try {
                    Permits::call($principals->suspend(...), new SuspendPrincipal($realm, $a->id), new Origin(OriginKind::Web));

                    break;
                } catch (GuardRuleViolated $violated) {
                    $refused = $violated;
                } catch (RetryableConflict) {
                    // Run again, as Operations::run() does: on what was committed.
                    ++$conflicts;
                }
            }

            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            unlink($start);
            if (file_exists($start.'.ready')) {
                unlink($start.'.ready');
            }
            $database->connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        self::assertSame(0, $exitCode, 'The revocation failed: '.$output);
        self::assertSame('REPEATABLE READ' === $isolation ? 1 : 0, $conflicts, 'A waiter on the realm\'s lock went on with what it saw before, or conflicted under READ COMMITTED.');
        self::assertNotNull($refused, 'The suspension didn\'t see the role taken at the same moment: no active administrator is left.');
        self::assertTrue(true === $principals->get($realm, $a->id)?->isActive());
        self::assertSame([(string) $a->id], array_map(strval(...), $stack->policy->principalsWith($realm, 'admin')));
    }

    /**
     * Until the other process holds what this one must wait for.
     */
    private static function waitFor(string $file): void
    {
        $deadline = microtime(true) + 10;

        while (!file_exists($file)) {
            if (microtime(true) > $deadline) {
                self::fail('The other process never got there.');
            }

            usleep(1_000);
        }
    }
}

/**
 * At least one active principal holds the administrator's role, as the change leaves it.
 */
final readonly class KeepAnAdministrator implements GuardRule
{
    public function __construct(
        private PrincipalStore $principals,
        private PolicyStore $policy,
    ) {
    }

    public function check(PrincipalChange $change): ?string
    {
        foreach ($this->policy->principalsWith($change->after->realm, 'admin') as $id) {
            if (true === $this->principals->get($change->after->realm, $id)?->isActive()) {
                return null;
            }
        }

        return 'The last active administrator can\'t be suspended.';
    }
}
