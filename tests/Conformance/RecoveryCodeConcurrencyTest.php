<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Throttle\Throttle;

/**
 * Four requests present the same recovery code at the same moment, each in its own process: exactly one
 * recovers the account (ATN-025). Each finds the principal's set before any uses the code, so checking
 * and then removing, without removing being the check, would let several through.
 */
final class RecoveryCodeConcurrencyTest extends DatabaseTestCase
{
    private const int PROCESSES = 4;

    public function testOnlyOneOfSeveralConcurrentRequestsRecoversWithACode(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $sessions = new Sessions($stack->sessions, $principals, $log, $stack->transactions);
        $stepUp = new StepUp($sessions, $stack->coolDowns, $log);
        $codes = new RecoveryCodes($stack->recoveryCodes, new Credentials($stack->credentials, $sessions, $stepUp, $log, $stack->transactions), $sessions, $stepUp, new Attempts(new Throttle($stack->throttle)), $log, $stack->transactions);
        $principal = $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = $sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web))->session;
        $code = $codes->generate(new GenerateRecoveryCodes(new Realm('acme')), $session, new Origin(OriginKind::Web))[0];
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $processes = [];

        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/use-recovery-code.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, (string) $principal->id, $code->reveal(), $start],
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
        self::assertSame([0, 3, 3, 3], $exitCodes, 'Expected one request to recover the account: '.implode(' | ', array_column($results, 1)));

        $used = array_filter(iterator_to_array($stack->log->entries(new Realm('acme'), 1), false), static fn (LogEntry $entry): bool => 'yorvoid.recovery_code.use' === $entry->action && Outcome::Success === $entry->outcome);
        self::assertCount(1, $used);
        self::assertSame(9, $codes->remaining(new Realm('acme'), $principal->id));
    }
}
