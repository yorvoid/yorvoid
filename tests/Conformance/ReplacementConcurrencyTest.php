<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Recovery\RedeemRecoveryCode;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Throttle;

/**
 * Two replacements at once, each in its own process, the first still in its transaction when the second
 * begins: what's left is one set of recovery codes, and one recovery link, the second's (ATN-025,
 * ATN-033). Deleting the old ones and adding new ones, without waiting for the other replacement, would
 * leave both.
 */
final class ReplacementConcurrencyTest extends DatabaseTestCase
{
    public function testTwoNewSetsOfRecoveryCodesAtOnceLeaveOneSet(): void
    {
        $stack = $this->stack('postgresql');
        [$principals, $sessions, $stepUp, $log] = $this->services($stack);
        $codes = new RecoveryCodes($stack->recoveryCodes, new Credentials($stack->credentials, $sessions, $stepUp, $log, $stack->transactions), $sessions, $stepUp, new Attempts(new Throttle($stack->throttle)), $log, $stack->transactions, $principals);
        $principal = Permits::call($principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = Permits::call($sessions->start(...), new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable(), true, true))), new Origin(OriginKind::Web))->session;
        Permits::call($codes->generate(...), new GenerateRecoveryCodes(new Realm('acme')), new Origin(OriginKind::Web), $session);

        [$first, $second] = $this->race($stack, 'codes', (string) $session->id);

        self::assertSame(10, $codes->remaining(new Realm('acme'), $principal->id), 'Both sets were kept.');
        self::assertNull(Permits::call($codes->redeem(...), new RedeemRecoveryCode(new Realm('acme'), $principal->id, Secret::fromString($first[0])), new Origin(OriginKind::Web)), 'The replaced set still works.');
        self::assertNotNull(Permits::call($codes->redeem(...), new RedeemRecoveryCode(new Realm('acme'), $principal->id, Secret::fromString($second[0])), new Origin(OriginKind::Web)));
    }

    public function testTwoRecoveryLinksAtOnceLeaveTheSecond(): void
    {
        $stack = $this->stack('postgresql');
        [$principals, , , $log] = $this->services($stack);
        $tokens = new OneTimeTokens($stack->tokens, $principals, $log, $stack->transactions);
        $principal = Permits::call($principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));

        [$first, $second] = $this->race($stack, 'link', (string) $principal->id);

        self::assertNull($tokens->peek(new Realm('acme'), RecoveryLinks::PURPOSE, Secret::fromString($first[0])), 'The replaced link still works.');
        self::assertNotNull($tokens->peek(new Realm('acme'), RecoveryLinks::PURPOSE, Secret::fromString($second[0])));
    }

    /**
     * @return array{Principals, Sessions, StepUp, SecurityLog}
     */
    private function services(Stack $stack): array
    {
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $sessions = new Sessions($stack->sessions, $principals, $log, $stack->transactions);

        return [$principals, $sessions, new StepUp($sessions, $stack->coolDowns, $log), $log];
    }

    /**
     * Starts the first replacement, which holds its transaction open for 700 ms once it has replaced,
     * then the second.
     *
     * @return array{list<string>, list<string>} What each made
     */
    private function race(Stack $stack, string $kind, string $id): array
    {
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $marker = \sprintf('%s/yorvoid-replaced-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $schema = $database->location->schema;
        $start = static function (string $marker, int $hold) use ($schema, $kind, $id): array {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/replace-secrets.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $schema, $kind, $id, $marker, (string) $hold],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);

            return [$process, $pipes];
        };
        $processes = [$start($marker.'-1', 700)];

        try {
            $deadline = microtime(true) + 10;

            while (!file_exists($marker.'-1')) {
                self::assertLessThan($deadline, microtime(true), 'The first replacement never got going.');
                usleep(1_000);
            }

            $processes[] = $start($marker.'-2', 0);
            $made = [];

            foreach ($processes as $i => [$process, $pipes]) {
                $output = (string) stream_get_contents($pipes[1]);
                $errors = (string) stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), $output.$errors);
                unset($processes[$i]);
                $secrets = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
                \assert(\is_array($secrets) && array_is_list($secrets));
                $made[] = array_map(static fn (mixed $secret): string => \is_string($secret) ? $secret : '', $secrets);
            }
        } finally {
            foreach ($processes as [$process]) {
                \is_resource($process) && proc_terminate($process);
            }

            foreach ([$marker.'-1', $marker.'-2'] as $file) {
                is_file($file) && unlink($file);
            }
        }

        return [$made[0], $made[1]];
    }
}
