<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Authn\Credential\AddCredential;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\LastCredential;
use YorvoID\Authn\Credential\RemoveCredential;
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
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;

/**
 * A principal's last way to sign in stays, also when two changes of their credentials come at once: two
 * sessions removing the last two passkeys, or a removal while a "not me" report disables the other. The
 * second waits for the first, on the principal's lock, and then counts what's left: it's refused.
 */
final class CredentialChangeConcurrencyTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function firstChanges(): iterable
    {
        yield 'a removal first' => ['remove'];
        yield 'a disable first' => ['disable'];
    }

    #[DataProvider('firstChanges')]
    public function testThePrincipalKeepsAWayToSignIn(string $first): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $realm = new Realm('acme');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $sessions = new Sessions($stack->sessions, $principals, $log, $stack->transactions);
        $credentials = new Credentials($stack->credentials, $sessions, $principals, new StepUp($sessions, $stack->coolDowns, $log), $log, $stack->transactions);
        $principal = Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = Permits::call($sessions->start(...), new StartSession($realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable(), true, true))), new Origin(OriginKind::Web))->session;
        $phone = Permits::call($credentials->add(...), new AddCredential($realm, CredentialKind::Passkey, 'Phone'), new Origin(OriginKind::Web), $session);
        $laptop = Permits::call($credentials->add(...), new AddCredential($realm, CredentialKind::Passkey, 'Laptop'), new Origin(OriginKind::Web), $session);
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../Support/change-credential.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, $first, (string) $phone->id, (string) $principal->id, (string) $session->id, $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $refused = false;

        try {
            touch($start);
            // Once the other process has changed the phone's passkey, before it commits.
            self::waitFor($start.'.ready');

            try {
                Permits::call($credentials->remove(...), new RemoveCredential($realm, $laptop->id), new Origin(OriginKind::Web), $session);
            } catch (LastCredential) {
                $refused = true;
            }

            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            unlink($start);
            if (file_exists($start.'.ready')) {
                unlink($start.'.ready');
            }
        }

        self::assertSame(0, $exitCode, 'The other change failed: '.$output);
        self::assertTrue($refused, 'Both changes went through: the principal has no way to sign in.');
        self::assertCount(1, array_filter($credentials->of($realm, $principal->id), static fn (Credential $credential): bool => $credential->isActive() && $credential->kind->signsIn()));
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
