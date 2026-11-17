<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

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
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
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
 * disables it and commits; then the sign-in goes on (ARC-0057). The credential stays disabled, and the
 * sign-in fails and is recorded. Writing the sign-in's copy of the credential back, as the whole
 * record, would make it active again, and let the sign-in through.
 */
final class DisabledDuringSignInTest extends DatabaseTestCase
{
    public function testARecoveryCodeSignInFailsWhenItsSetIsDisabledMeanwhile(): void
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
        $reading = new ReadThenRun($signIn->credentials);
        $reading->arm(static function () use ($ownerCredentials, $set): void {
            Permits::call($ownerCredentials->disable(...), new DisableCredential($set->realm, $set->id, $set->principalId, 'its principal said they didn\'t add it'), new Origin(OriginKind::Web));
        });
        $codes = $this->codes($signIn, $this->credentials($signIn, $reading));

        $started = Permits::call($codes->redeem(...), new RedeemRecoveryCode(new Realm('acme'), $principal->id, $code), new Origin(OriginKind::Web));

        self::assertTrue($reading->ran, 'The sign-in never read the credential.');
        self::assertNull($started, 'A sign-in with a set disabled meanwhile started a session.');
        self::assertSame(CredentialStatus::Disabled, $ownerCredentials->get(new Realm('acme'), $set->id)?->status, 'The sign-in made the disabled set active again.');
        self::assertSame(10, $codes->remaining(new Realm('acme'), $principal->id), 'The code was used up although the sign-in failed.');
        $uses = array_values(array_filter(iterator_to_array($owner->log->entries(new Realm('acme'), 1), false), static fn (LogEntry $entry): bool => 'yorvoid.recovery_code.use' === $entry->action));
        self::assertCount(1, $uses);
        self::assertSame(Outcome::Failed, $uses[0]->outcome, 'The failed sign-in wasn\'t recorded.');
        self::assertSame(['credential', (string) $set->id, ['principal' => (string) $principal->id]], [$uses[0]->subjectType, $uses[0]->subjectId, $uses[0]->details]);
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
        [, $sessions, $stepUp, $log] = $this->services($stack);

        return new Credentials($store, $sessions, $stepUp, $log, $stack->transactions);
    }

    private function codes(Stack $stack, Credentials $credentials): RecoveryCodes
    {
        [$principals, $sessions, $stepUp, $log] = $this->services($stack);

        return new RecoveryCodes($stack->recoveryCodes, $credentials, $sessions, $stepUp, new Attempts(new Throttle($stack->throttle)), $log, $stack->transactions, $principals);
    }
}
