<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSessionsOfInactivePrincipals;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * A session started while its principal is suspended: whichever takes the principal's lock first, the
 * other waits for it, and then sees it. Under READ COMMITTED it reads what was committed; under
 * REPEATABLE READ it conflicts, and run again it decides on what was committed. A suspended principal
 * never keeps a session started at the same moment.
 */
final class SessionStartConcurrencyTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function interleavings(): iterable
    {
        foreach (['read committed', 'repeatable read'] as $isolation) {
            yield 'the session first, '.$isolation => ['start', $isolation];
            yield 'the suspension first, '.$isolation => ['suspend', $isolation];
        }
    }

    #[DataProvider('interleavings')]
    public function testASuspendedPrincipalKeepsNoSessionStartedAtTheSameMoment(string $first, string $isolation): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $realm = new Realm('acme');
        $events = new RecordingDispatcher();
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, $events);
        $sessions = new Sessions($stack->sessions, $principals, $log, $stack->transactions);
        $events->listen(PrincipalStatusChanging::class, new EndSessionsOfInactivePrincipals($sessions)(...));
        $principal = Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Console));
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../Support/session-or-suspend.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, (string) $principal->id, $first, $isolation, $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $database->connection->executeStatement(\sprintf('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL %s', strtoupper($isolation)));
        $conflicts = 0;
        $refused = false;

        try {
            touch($start);
            // While the other process holds the principal's lock.
            usleep(250_000);

            for ($attempt = 1; $attempt <= 2; ++$attempt) {
                try {
                    'start' === $first
                        ? Permits::call($principals->suspend(...), new SuspendPrincipal($realm, $principal->id), new Origin(OriginKind::Web))
                        : Permits::call($sessions->start(...), new StartSession($realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable(), true, true))), new Origin(OriginKind::Web));

                    break;
                } catch (RetryableConflict) {
                    // Run again, as Operations::run() does: on what was committed.
                    ++$conflicts;
                } catch (InactivePrincipal) {
                    $refused = true;

                    break;
                }
            }

            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            unlink($start);
            $database->connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        self::assertSame(0, $exitCode, 'The other process failed: '.$output);
        self::assertSame('repeatable read' === $isolation ? 1 : 0, $conflicts, 'A waiter on the principal\'s lock went on with what it saw before, or conflicted under READ COMMITTED.');
        self::assertSame('suspend' === $first, $refused, 'A session started for a suspended principal.');
        self::assertSame(PrincipalStatus::Suspended, $principals->get($realm, $principal->id)?->status);
        self::assertSame([], $stack->sessions->current($realm, $principal->id, new \DateTimeImmutable()), 'The suspended principal kept a session started at the same moment.');
    }
}
