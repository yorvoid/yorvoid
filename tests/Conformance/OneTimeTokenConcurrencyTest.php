<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Four requests present the same one-time token at the same moment, each in its own process: exactly
 * one gets it (ATN-030), as the formal model's atomic consumption requires. Each finds the token before
 * any uses it, so checking and then deleting, without deleting being the check, would let several
 * through.
 */
final class OneTimeTokenConcurrencyTest extends DatabaseTestCase
{
    private const int PROCESSES = 4;

    public function testOnlyOneOfSeveralConcurrentRequestsGetsAToken(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $tokens = new OneTimeTokens($stack->tokens, $principals, $log, $stack->transactions);
        $principal = $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $issued = $tokens->issue(new IssueToken(new Realm('acme'), $principal->id, 'acme.test', Duration::hours(1)), new Origin(OriginKind::Console));
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $processes = [];

        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/consume-token.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, $issued->secret->reveal(), $start],
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
        self::assertSame([0, 3, 3, 3], $exitCodes, 'Expected one request to get the token: '.implode(' | ', array_column($results, 1)));

        $consumed = array_filter(iterator_to_array($stack->log->entries(new Realm('acme'), 1), false), static fn ($entry): bool => 'yorvoid.token.consume' === $entry->action);
        self::assertCount(1, $consumed);
    }
}
