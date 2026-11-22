<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Passkeys\Passkey;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Refusal;
use YorvoID\Passkeys\RegisterPasskey;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Throttle\Throttle;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * A principal keeps one user handle: two first passkeys registered at once, each from a ceremony that
 * gave it a new handle, meet on the principal's lock. The second waits for the first, sees it, and
 * starts again.
 */
final class PasskeyRegistrationConcurrencyTest extends DatabaseTestCase
{
    public function testTwoFirstPasskeysAtOnceKeepOneUserHandle(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $realm = new Realm('acme');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $sessions = new Sessions($stack->sessions, $principals, $log, $stack->transactions);
        $credentials = new Credentials($stack->credentials, $sessions, $principals, new StepUp($sessions, $stack->coolDowns, $log), $log, $stack->transactions);
        $passkeys = new Passkeys($stack->passkeys, $stack->ceremonies, $credentials, $sessions, $principals, $log, $stack->transactions, new PasskeyPolicy('example.com', ['https://example.com']), new Attempts(new Throttle($stack->throttle)));
        $principal = Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = Permits::call($sessions->start(...), new StartSession($realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new \DateTimeImmutable()))), new Origin(OriginKind::Web))->session;
        // Two tabs begin the first registration at once: each gets a new handle.
        $first = $passkeys->beginRegistration($session);
        $second = $passkeys->beginRegistration($session);
        $response = \sprintf('%s/yorvoid-response-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        file_put_contents($response, new VirtualAuthenticator()->register($first->options));
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../Support/register-passkey.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, (string) $session->id, $first->id, $response, $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $refusal = null;

        try {
            touch($start);
            // Once the other process has stored the first passkey, before it commits.
            self::waitFor($start.'.ready');

            try {
                Permits::call($passkeys->finishRegistration(...), new RegisterPasskey($realm, $second->id, new VirtualAuthenticator()->register($second->options), 'Laptop'), new Origin(OriginKind::Web), $session);
            } catch (PasskeyRefused $refused) {
                $refusal = $refused->refusal;
            }

            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            unlink($start);
            if (file_exists($start.'.ready')) {
                unlink($start.'.ready');
            }
            unlink($response);
        }

        self::assertSame(0, $exitCode, 'The first registration failed: '.$output);
        self::assertSame(Refusal::CeremonyExpired, $refusal, 'The second registration didn\'t see the first.');
        self::assertCount(1, array_unique(array_map(static fn (Passkey $passkey): string => $passkey->userHandle, $passkeys->of($realm, $principal->id))));
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
