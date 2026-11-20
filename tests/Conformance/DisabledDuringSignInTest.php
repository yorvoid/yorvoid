<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Doctrine\DBAL\Exception\DriverException;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\DisableCredential;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RedeemRecoveryCode;
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
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\ReadThenRun;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Throttle;

/**
 * A sign-in reads the credential as active; on another connection, the owner's "not me" report
 * disables it (ARC-0057). The sign-in locked its principal first (D-52), so the disable waits for it
 * rather than slipping in between: it can't commit until the sign-in has ended, and then disables the
 * set, which the next sign-in sees.
 */
final class DisabledDuringSignInTest extends DatabaseTestCase
{
    public function testADisableWaitsForARecoveryCodeSignInInProgress(): void
    {
        $owner = $this->stack('postgresql');
        $signIn = $this->stack('postgresql');
        $ownerCredentials = $this->credentials($owner, $owner->credentials);
        [$principals, $sessions] = $this->services($owner);
        $principal = Permits::call($principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Console));
        $session = Permits::call($sessions->start(...), new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable(), true, true))), new Origin(OriginKind::Web))->session;
        $code = Permits::call($this->codes($owner, $ownerCredentials)->generate(...), new GenerateRecoveryCodes(new Realm('acme')), new Origin(OriginKind::Web), $session)[0];
        $set = $this->set($ownerCredentials, $principal->id);

        // What NotMe::confirm() does to the credential, on the owner's connection, once the sign-in has read it.
        $ownerDatabase = $owner->database;
        \assert(null !== $ownerDatabase);
        $waited = null;
        $reading = new ReadThenRun($signIn->credentials);
        $reading->arm(static function () use ($ownerCredentials, $ownerDatabase, $set, &$waited): void {
            $ownerDatabase->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                Permits::call($ownerCredentials->disable(...), new DisableCredential($set->realm, $set->id, $set->principalId, 'its principal said they didn\'t add it'), new Origin(OriginKind::Web));
            } catch (DriverException $timedOut) {
                // lock_not_available: it waited for the sign-in's lock on the principal.
                $waited = $timedOut->getSQLState();
            } finally {
                $ownerDatabase->connection->executeStatement('RESET lock_timeout');
            }
        });
        $codes = $this->codes($signIn, $this->credentials($signIn, $reading));

        $started = Permits::call($codes->redeem(...), new RedeemRecoveryCode(new Realm('acme'), $principal->id, $code), new Origin(OriginKind::Web));

        self::assertTrue($reading->ran, 'The sign-in never read the credential.');
        self::assertSame('55P03', $waited, 'The disable didn\'t wait for the sign-in in progress.');
        self::assertNotNull($started, 'The sign-in failed on a disable that didn\'t happen.');
        self::assertSame(CredentialStatus::Active, $ownerCredentials->get(new Realm('acme'), $set->id)?->status);

        // Once the sign-in has ended, the disable goes through, and a sign-in after it fails.
        Permits::call($ownerCredentials->disable(...), new DisableCredential($set->realm, $set->id, $set->principalId, 'its principal said they didn\'t add it'), new Origin(OriginKind::Web));
        self::assertSame(CredentialStatus::Disabled, $ownerCredentials->get(new Realm('acme'), $set->id)->status);
        self::assertNull(Permits::call($codes->redeem(...), new RedeemRecoveryCode(new Realm('acme'), $principal->id, $code), new Origin(OriginKind::Web)), 'A disabled set still signed in.');
    }

    private function set(Credentials $credentials, PrincipalId $principalId): Credential
    {
        foreach ($credentials->of(new Realm('acme'), $principalId) as $credential) {
            if (CredentialKind::RecoveryCodes === $credential->kind) {
                return $credential;
            }
        }

        self::fail('The principal has no recovery codes.');
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

    private function credentials(Stack $stack, CredentialStore $store): Credentials
    {
        [$principals, $sessions, $stepUp, $log] = $this->services($stack);

        return new Credentials($store, $sessions, $principals, $stepUp, $log, $stack->transactions);
    }

    private function codes(Stack $stack, Credentials $credentials): RecoveryCodes
    {
        [$principals, $sessions, $stepUp, $log] = $this->services($stack);

        return new RecoveryCodes($stack->recoveryCodes, $credentials, $sessions, $stepUp, new Attempts(new Throttle($stack->throttle)), $log, $stack->transactions, $principals);
    }
}
