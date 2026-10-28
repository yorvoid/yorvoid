<?php

declare(strict_types=1);

namespace YorvoID\Tests\Passkeys;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Authn\Credential\Event\CredentialRemoving;
use YorvoID\Authn\Credential\RemoveCredential;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Ceremony;
use YorvoID\Passkeys\ClonePolicy;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Refusal;
use YorvoID\Passkeys\RemovePasskeyWithCredential;
use YorvoID\Passkeys\UserVerification;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;
use YorvoID\Tests\Support\VirtualAuthenticator;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Passkeys on every implementation (ATN-001…003, ATN-005, ATN-006, ATN-027), with a software
 * authenticator.
 */
final class PasskeysTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private RecordingDispatcher $events;

    private Principals $principals;

    private Sessions $sessions;

    private Credentials $credentials;

    private StepUp $stepUp;

    #[DataProvider('implementations')]
    public function testRegistersAPasskeyAndSignsInWithIt(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session(email: 'ana@example.com');
        $authenticator = new VirtualAuthenticator();

        $ceremony = $passkeys->beginRegistration($session);
        $credential = $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'MacBook', new Origin(OriginKind::Web));

        self::assertSame('MacBook', $credential->label);
        $stored = $passkeys->of(new Realm('acme'), $session->principalId);
        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->credentialId->equals($credential->id));
        self::assertSame($authenticator->credentials[0]['id'], $stored[0]->webauthnId);
        self::assertSame($authenticator->credentials[0]['userHandle'], $stored[0]->userHandle);
        self::assertSame(32, \strlen($stored[0]->userHandle), 'The user handle is random, not personal data.');
        self::assertSame(['internal', 'hybrid'], $stored[0]->transports);
        self::assertTrue($stored[0]->userVerificationInitialized);
        self::assertFalse($stored[0]->backupEligible);
        self::assertSame('none', $stored[0]->attestationType);

        $this->clock->sleep(3600);
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $started = $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options), new Origin(OriginKind::Web), 'Firefox on Linux', '192.0.2.10');

        self::assertTrue($started->session->principalId->equals($session->principalId));
        $factor = $started->session->authentication->factors[0];
        self::assertSame([AuthenticationMethod::Passkey, true, true], [$factor->method, $factor->multiFactor, $factor->phishingResistant], 'A passkey with user verification is two factors, and phishing-resistant.');
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$started->session->device, $started->session->address]);
        self::assertSame(1, $passkeys->of(new Realm('acme'), $session->principalId)[0]->signCount);
        self::assertEquals($this->clock->now()->setTime(19, 45, 30), $this->credentials->get(new Realm('acme'), $credential->id)?->lastUsedAt);
    }

    #[DataProvider('implementations')]
    public function testTheOptionsAreYorvoIDsPolicy(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session(email: 'ana@example.com', username: 'ana');
        $authenticator = new VirtualAuthenticator();
        $first = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $first->id, $authenticator->register($first->options), 'Phone', new Origin(OriginKind::Web));

        $registration = self::json($passkeys->beginRegistration($session)->options);
        $signIn = self::json($passkeys->beginSignIn(new Realm('acme'))->options);

        self::assertSame(['id' => 'example.com', 'name' => 'example.com'], $registration['rp'], 'WebAuthn Level 3 dropped the name; the library sends the ID, for older browsers.');
        self::assertSame(['name' => 'ana@example.com', 'displayName' => 'ana@example.com'], array_diff_key((array) $registration['user'], ['id' => true]), 'The email address, which people recognise, before the username.');
        self::assertSame(VirtualAuthenticator::encode($authenticator->credentials[0]['userHandle']), ((array) $registration['user'])['id'], 'A principal keeps one user handle.');
        self::assertSame([-7, -8, -257], array_column((array) $registration['pubKeyCredParams'], 'alg'));
        self::assertSame(['requireResidentKey' => true, 'userVerification' => 'required', 'residentKey' => 'required'], $registration['authenticatorSelection']);
        self::assertSame('none', $registration['attestation']);
        self::assertSame(300000, $registration['timeout']);
        self::assertSame([VirtualAuthenticator::encode($authenticator->credentials[0]['id'])], array_column((array) $registration['excludeCredentials'], 'id'), 'The authenticator isn\'t asked to make a second passkey for the same account.');
        \assert(\is_string($registration['challenge']));
        self::assertSame(43, \strlen($registration['challenge']), '256 random bits.');

        \assert(\is_string($signIn['challenge']));
        self::assertSame(43, \strlen($signIn['challenge']), '256 random bits.');
        self::assertSame('example.com', $signIn['rpId']);
        self::assertSame([], $signIn['allowCredentials'], 'Discoverable credentials: the browser offers the passkeys, no username first.');
        self::assertSame('required', $signIn['userVerification']);
    }

    #[DataProvider('implementations')]
    public function testRegisteringNeedsARecentProofOfIdentity(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $this->clock->sleep(200);
        $ceremony = $passkeys->beginRegistration($session);
        $response = new VirtualAuthenticator()->register($ceremony->options);

        // The ceremony still works for 200 seconds; the proof of identity is 5 minutes old.
        $this->clock->sleep(100);

        try {
            $passkeys->finishRegistration($session, $ceremony->id, $response, 'Attacker\'s', new Origin(OriginKind::Web));
            self::fail('A passkey was added from a session that proved nothing recently.');
        } catch (StepUpRequired) {
        }

        self::assertSame([], $passkeys->of(new Realm('acme'), $session->principalId));

        $this->expectException(StepUpRequired::class);

        $passkeys->beginRegistration($session);
    }

    #[DataProvider('implementations')]
    public function testUserVerificationIsRequiredWhateverTheBrowserSays(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();

        $ceremony = $passkeys->beginRegistration($session);
        $this->refused(Refusal::UserNotVerified, static fn () => $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options, userVerified: false), 'Phone', new Origin(OriginKind::Web)));

        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Phone', new Origin(OriginKind::Web));
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::UserNotVerified, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, credential: 1, userVerified: false), new Origin(OriginKind::Web)));

        $entry = \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), -1)[0];
        self::assertSame(['yorvoid.passkey.sign_in', Outcome::Failed], [$entry->action, $entry->outcome]);
        self::assertSame(['principal' => (string) $session->principalId, 'reason' => 'user_not_verified'], $entry->details);
    }

    /**
     * GHSA-h4fw-6r7f-w494: webauthn-lib 5.3.0 took the user-verification requirement from the options, so
     * options saying less than the policy let a passkey through without it. YorvoID's options are its
     * own and kept server-side; even if they said "discouraged", YorvoID checks the flag itself.
     */
    #[DataProvider('implementations')]
    public function testOptionsThatAskForLessDontLowerTheRequirement(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Phone', new Origin(OriginKind::Web));

        foreach (['discouraged', 'preferred'] as $downgrade) {
            $signIn = $passkeys->beginSignIn(new Realm('acme'));
            $stored = $this->stack->ceremonies->take(new Realm('acme'), \Symfony\Component\Uid\Uuid::fromString($signIn->id));
            \assert($stored instanceof Ceremony);
            $weakened = str_replace('"userVerification":"required"', '"userVerification":"'.$downgrade.'"', $stored->options);
            self::assertNotSame($stored->options, $weakened);
            $this->stack->ceremonies->add(new Ceremony($stored->id, $stored->realm, $stored->kind, $weakened, $stored->expiresAt));

            $this->refused(Refusal::UserNotVerified, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($weakened, userVerified: false), new Origin(OriginKind::Web)));
        }
    }

    #[DataProvider('implementations')]
    public function testWhenOnlyPreferredAPasskeyWithoutVerificationIsOneFactor(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation, userVerification: UserVerification::Preferred);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        self::assertSame('preferred', ((array) self::json($ceremony->options)['authenticatorSelection'])['userVerification']);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options, userVerified: false), 'Key', new Origin(OriginKind::Web));
        self::assertFalse($passkeys->of(new Realm('acme'), $session->principalId)[0]->userVerificationInitialized);

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $started = $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, userVerified: false), new Origin(OriginKind::Web));

        self::assertFalse($started->session->authentication->factors[0]->multiFactor, 'Without user verification, a passkey is one factor (NIST SP 800-63B-4, appendix B).');
        self::assertTrue($started->session->authentication->factors[0]->phishingResistant);
        self::assertFalse($passkeys->of(new Realm('acme'), $session->principalId)[0]->userVerificationInitialized);

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        self::assertTrue($passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options), new Origin(OriginKind::Web))->session->authentication->factors[0]->multiFactor);
        self::assertTrue($passkeys->of(new Realm('acme'), $session->principalId)[0]->userVerificationInitialized, 'The first verified use was recorded.');
    }

    #[DataProvider('implementations')]
    public function testACeremonyWorksOnceForWhatItBegan(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $other = $this->session();
        $authenticator = new VirtualAuthenticator();
        $registration = $passkeys->beginRegistration($session);
        $response = $authenticator->register($registration->options);

        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishRegistration($other, $registration->id, $response, 'Phone', new Origin(OriginKind::Web)));
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishRegistration($session, $registration->id, $response, 'Phone', new Origin(OriginKind::Web)), 'Another session\'s attempt used the ceremony up.');

        $registration = $passkeys->beginRegistration($session);
        $response = $authenticator->register($registration->options);
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishSignIn(new Realm('acme'), $registration->id, $response, new Origin(OriginKind::Web)));

        $registration = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Phone', new Origin(OriginKind::Web));
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $answer = $authenticator->signIn($signIn->options, credential: 2);
        $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $answer, new Origin(OriginKind::Web));
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $answer, new Origin(OriginKind::Web)), 'A sign-in was replayed.');

        $elsewhere = $passkeys->beginSignIn(new Realm('globex'));
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishSignIn(new Realm('acme'), $elsewhere->id, $authenticator->signIn($elsewhere->options, credential: 2), new Origin(OriginKind::Web)));
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishSignIn(new Realm('acme'), 'not-an-id', $answer, new Origin(OriginKind::Web)));

        $late = $passkeys->beginSignIn(new Realm('acme'));
        $this->clock->sleep(300);
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishSignIn(new Realm('acme'), $late->id, $authenticator->signIn($late->options, credential: 2), new Origin(OriginKind::Web)));
    }

    #[DataProvider('implementations')]
    public function testRefusesAnswersForAnotherSiteOrChallenge(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $registration = $passkeys->beginRegistration($session);
        $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options, origin: 'https://examp1e.com'), 'Phone', new Origin(OriginKind::Web)));
        $registration = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Phone', new Origin(OriginKind::Web));

        foreach ([
            'a look-alike origin' => ['origin' => 'https://examp1e.com'],
            'an origin of the domain the policy doesn\'t list' => ['origin' => 'https://evil.example.com'],
            'another relying party' => ['rpId' => 'evil.example'],
            'another challenge' => ['challenge' => VirtualAuthenticator::encode(random_bytes(32))],
        ] as $what => $tampering) {
            $signIn = $passkeys->beginSignIn(new Realm('acme'));
            $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, 1, true, null, $tampering['origin'] ?? null, $tampering['challenge'] ?? null, $tampering['rpId'] ?? null), new Origin(OriginKind::Web)), \sprintf('An answer with %s was accepted.', $what));
        }

        foreach (['', 'null', '{}', '{"id": "x", "rawId": "x", "type": "public-key", "response": {}}', $authenticator->register($registration->options)] as $garbage) {
            $signIn = $passkeys->beginSignIn(new Realm('acme'));
            $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $garbage, new Origin(OriginKind::Web)));
        }
    }

    #[DataProvider('implementations')]
    public function testOnlyRegisteredActivePasskeysOfActivePrincipalsSignIn(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $this->events->listen(CredentialRemoving::class, new RemovePasskeyWithCredential($this->stack->passkeys)(...));
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $stranger = new VirtualAuthenticator();
        $stranger->register($passkeys->beginRegistration($this->session())->options);

        $credentials = [];

        for ($i = 0; $i < 3; ++$i) {
            $ceremony = $passkeys->beginRegistration($session);
            $credentials[] = $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Key '.$i, new Origin(OriginKind::Web));
        }

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::UnknownPasskey, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $stranger->signIn($signIn->options), new Origin(OriginKind::Web)));

        $this->credentials->remove(new RemoveCredential(new Realm('acme'), $credentials[0]->id), $session, new Origin(OriginKind::Web));
        self::assertCount(2, $passkeys->of(new Realm('acme'), $session->principalId), 'The removed passkey\'s key was kept.');
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::UnknownPasskey, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, credential: 0), new Origin(OriginKind::Web)));

        // Without the listener, the key stays, but the registry entry is gone: it doesn't sign in either.
        $this->stack->credentials->remove(new Realm('acme'), $credentials[2]->id);
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::UnknownPasskey, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, credential: 2), new Origin(OriginKind::Web)));
        $ceremony = $passkeys->beginRegistration($session);
        $credentials[] = $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Key 3', new Origin(OriginKind::Web));

        $this->credentials->disable($credentials[1], 'reported lost', new Origin(OriginKind::Web));
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::PasskeyDisabled, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, credential: 1), new Origin(OriginKind::Web)));

        $this->principals->suspend(new SuspendPrincipal(new Realm('acme'), $session->principalId), new Origin(OriginKind::Console));
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::PrincipalInactive, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, credential: 3), new Origin(OriginKind::Web)));

        $signIn = $passkeys->beginSignIn(new Realm('globex'));
        $this->refused(Refusal::UnknownPasskey, static fn () => $passkeys->finishSignIn(new Realm('globex'), $signIn->id, $authenticator->signIn($signIn->options, credential: 3), new Origin(OriginKind::Web)), 'A passkey worked in another realm.');
    }

    #[DataProvider('implementations')]
    public function testAnAnswerClaimingAnotherAccountIsRefused(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $ana = $this->session();
        $bob = $this->session();
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($ana);
        $passkeys->finishRegistration($ana, $ceremony->id, $authenticator->register($ceremony->options), 'Ana\'s', new Origin(OriginKind::Web));
        $ceremony = $passkeys->beginRegistration($bob);
        $passkeys->finishRegistration($bob, $ceremony->id, new VirtualAuthenticator()->register($ceremony->options), 'Bob\'s', new Origin(OriginKind::Web));

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $bobsHandle = $passkeys->of(new Realm('acme'), $bob->principalId)[0]->userHandle;
        $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, userHandle: $bobsHandle), new Origin(OriginKind::Web)));
        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, userHandle: ''), new Origin(OriginKind::Web)), 'An answer naming no account was accepted.');
    }

    #[DataProvider('implementations')]
    public function testEdDsaPasskeysWork(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator(edwards: true);
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Ed25519', new Origin(OriginKind::Web));

        $signIn = $passkeys->beginSignIn(new Realm('acme'));

        self::assertTrue($passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options), new Origin(OriginKind::Web))->session->principalId->equals($session->principalId));
    }

    #[DataProvider('implementations')]
    public function testAPasskeyIsRegisteredOnce(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Phone', new Origin(OriginKind::Web));

        $again = $passkeys->beginRegistration($session);
        $this->refused(Refusal::AlreadyRegistered, static fn () => $passkeys->finishRegistration($session, $again->id, $authenticator->register($again->options, credentialId: $authenticator->credentials[0]['id']), 'Phone again', new Origin(OriginKind::Web)));

        self::assertCount(1, $this->credentials->of(new Realm('acme'), $session->principalId), 'A refused registration left a credential behind.');
    }

    #[DataProvider('implementations')]
    public function testACounterThatDoesntGoUpIsASuspectedCopy(string $implementation): void
    {
        foreach (ClonePolicy::cases() as $policy) {
            $passkeys = $this->passkeys($implementation, clonePolicy: $policy);
            $session = $this->session();
            $authenticator = new VirtualAuthenticator();
            $ceremony = $passkeys->beginRegistration($session);
            $credential = $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Key', new Origin(OriginKind::Web));
            $signIn = $passkeys->beginSignIn(new Realm('acme'));
            $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, counter: 5), new Origin(OriginKind::Web));

            $signIn = $passkeys->beginSignIn(new Realm('acme'));
            $copy = $authenticator->signIn($signIn->options, counter: 5);

            if (ClonePolicy::Allow === $policy) {
                $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $copy, new Origin(OriginKind::Web));
                $entry = \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), -2)[0];
                self::assertSame('yorvoid.passkey.suspected_copy', $entry->action);
                self::assertSame(['principal' => (string) $session->principalId], $entry->details);
            } else {
                $this->refused(Refusal::SuspectedCopy, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $copy, new Origin(OriginKind::Web)));
                self::assertSame(5, $passkeys->of(new Realm('acme'), $session->principalId)[0]->signCount);
            }

            self::assertSame(ClonePolicy::DisablePasskey === $policy ? CredentialStatus::Disabled : CredentialStatus::Active, $this->credentials->get(new Realm('acme'), $credential->id)?->status, $policy->value);
        }
    }

    #[DataProvider('implementations')]
    public function testACounterStartingAtZeroMayGoUpButNotBackToZero(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Key', new Origin(OriginKind::Web));

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, counter: 3), new Origin(OriginKind::Web));
        self::assertSame(3, $passkeys->of(new Realm('acme'), $session->principalId)[0]->signCount, 'A key whose count starts at 0 counts up.');

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::SuspectedCopy, static fn () => $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options, counter: 0), new Origin(OriginKind::Web)));
        $entry = \array_slice(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), -1)[0];
        self::assertSame(['principal' => (string) $session->principalId, 'reason' => 'suspected_copy'], $entry->details);
    }

    #[DataProvider('implementations')]
    public function testSyncedPasskeysAreNeverSuspected(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator(synced: true);
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'iCloud Keychain', new Origin(OriginKind::Web));
        $registered = $passkeys->of(new Realm('acme'), $session->principalId)[0];
        self::assertSame([true, true], [$registered->backupEligible, $registered->backedUp]);

        for ($i = 0; $i < 3; ++$i) {
            $signIn = $passkeys->beginSignIn(new Realm('acme'));
            $passkeys->finishSignIn(new Realm('acme'), $signIn->id, $authenticator->signIn($signIn->options), new Origin(OriginKind::Web));
        }

        $stored = $passkeys->of(new Realm('acme'), $session->principalId)[0];
        self::assertSame([0, true, true], [$stored->signCount, $stored->backupEligible, $stored->backedUp], 'Backup isn\'t grounds for refusal (NIST SP 800-63B-4, appendix B).');
    }

    #[DataProvider('implementations')]
    public function testASessionOfAPrincipalThatIsntThereRegistersNothing(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $ghost = new Session(new SessionId(new UuidV7Generator()->next()), new Realm('acme'), new PrincipalId(new UuidV7Generator()->next()), 1, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now())), $this->clock->now(), $this->clock->now(), $this->clock->now()->modify('+1 hour'), $this->clock->now()->modify('+1 hour'));
        $this->stack->sessions->add($ghost, hash('sha256', 'ghost'));

        $this->expectException(UnknownPrincipal::class);

        $passkeys->beginRegistration($ghost);
    }

    #[DataProvider('implementations')]
    public function testStepsUpWithAPasskeyForAnOperation(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $registration = $passkeys->beginRegistration($session);
        $credential = $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Phone', new Origin(OriginKind::Web));
        $this->clock->sleep(1800);
        $removing = new AuthenticationRequirement(Duration::minutes(5), multiFactor: true, phishingResistant: true, operation: 'acme.site.remove');

        $ceremony = $passkeys->beginStepUp($session, $removing);

        $options = self::json($ceremony->options);
        self::assertSame([VirtualAuthenticator::encode($authenticator->credentials[0]['id'])], array_column((array) $options['allowCredentials'], 'id'), 'Only this person\'s passkeys are offered.');
        $allowed = (array) $options['allowCredentials'];
        \assert(\is_array($allowed[0]));
        self::assertSame(['internal', 'hybrid'], $allowed[0]['transports']);
        self::assertSame(['example.com', 'required', 300000], [$options['rpId'], $options['userVerification'], $options['timeout']]);
        \assert(\is_string($options['challenge']));
        self::assertSame(43, \strlen($options['challenge']), '256 random bits.');

        $token = $passkeys->finishStepUp($session, $ceremony->id, $authenticator->signIn($ceremony->options), new Origin(OriginKind::Web));

        $steppedUp = $this->sessions->validate(new Realm('acme'), $token);
        self::assertNotNull($steppedUp);
        self::assertTrue($steppedUp->id->equals($session->id), 'The step-up started another session.');
        self::assertEquals(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true, 'acme.site.remove'), $steppedUp->authentication->factors[1]);
        $this->stepUp->require($session, $removing);
        self::assertSame(1, $passkeys->of(new Realm('acme'), $session->principalId)[0]->signCount);
        self::assertEquals($this->clock->now(), $this->credentials->get(new Realm('acme'), $credential->id)?->lastUsedAt);

        $this->expectException(StepUpRequired::class);

        $this->stepUp->require($session, new AuthenticationRequirement(Duration::minutes(5), operation: 'acme.site.transfer'));
    }

    #[DataProvider('implementations')]
    public function testAStepUpIsTheSessionsAndItsPrincipals(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $other = $this->session();
        $authenticator = new VirtualAuthenticator();
        $stranger = new VirtualAuthenticator();
        $registration = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Phone', new Origin(OriginKind::Web));
        $registration = $passkeys->beginRegistration($other);
        $passkeys->finishRegistration($other, $registration->id, $stranger->register($registration->options), 'Phone', new Origin(OriginKind::Web));
        $requirement = new AuthenticationRequirement(Duration::minutes(5));

        $ceremony = $passkeys->beginStepUp($session, $requirement);
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishStepUp($other, $ceremony->id, $stranger->signIn($ceremony->options), new Origin(OriginKind::Web)), 'Another session finished the step-up.');

        $entries = \count(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false));
        $ceremony = $passkeys->beginStepUp($session, $requirement);
        $this->refused(Refusal::UnknownPasskey, static fn () => $passkeys->finishStepUp($session, $ceremony->id, $stranger->signIn($ceremony->options), new Origin(OriginKind::Web)), 'Someone else\'s passkey stepped the session up.');
        self::assertCount($entries, iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), 'Someone else\'s passkey was recorded as theirs.');

        $signIn = $passkeys->beginSignIn(new Realm('acme'));
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishStepUp($session, $signIn->id, $authenticator->signIn($signIn->options), new Origin(OriginKind::Web)));
        $ceremony = $passkeys->beginStepUp($session, $requirement);
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishSignIn(new Realm('acme'), $ceremony->id, $authenticator->signIn($ceremony->options), new Origin(OriginKind::Web)), 'A step-up ceremony started a session.');

        $ceremony = $passkeys->beginStepUp($session, $requirement);
        $answer = $authenticator->signIn($ceremony->options);
        $this->sessions->end(new EndSession(new Realm('acme'), $session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        $this->refused(Refusal::CeremonyExpired, static fn () => $passkeys->finishStepUp($session, $ceremony->id, $answer, new Origin(OriginKind::Web)), 'An ended session stepped up.');
        self::assertSame(0, $passkeys->of(new Realm('acme'), $session->principalId)[0]->signCount, 'A refused step-up was recorded as a use.');
    }

    #[DataProvider('implementations')]
    public function testOnlyAWorkingPasskeyStepsUp(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $requirement = new AuthenticationRequirement(Duration::minutes(5));
        $this->refused(Refusal::NoPasskey, static fn () => $passkeys->beginStepUp($session, $requirement));

        $credentials = [];

        for ($i = 0; $i < 2; ++$i) {
            $registration = $passkeys->beginRegistration($session);
            $credentials[] = $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Key '.$i, new Origin(OriginKind::Web));
        }

        $this->credentials->disable($credentials[0], 'reported lost', new Origin(OriginKind::Web));
        $ceremony = $passkeys->beginStepUp($session, $requirement);
        self::assertSame([VirtualAuthenticator::encode($authenticator->credentials[1]['id'])], array_column((array) self::json($ceremony->options)['allowCredentials'], 'id'), 'A disabled passkey was offered.');

        $this->refused(Refusal::PasskeyDisabled, static fn () => $passkeys->finishStepUp($session, $ceremony->id, $authenticator->signIn($ceremony->options, credential: 0), new Origin(OriginKind::Web)));
        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        $failed = $entries[\count($entries) - 1];
        self::assertSame(['yorvoid.passkey.step_up', Outcome::Failed, (string) $credentials[0]->id], [$failed->action, $failed->outcome, $failed->subjectId]);
        self::assertSame(['principal' => (string) $session->principalId, 'reason' => 'passkey_disabled'], $failed->details);

        $this->credentials->disable($credentials[1], 'reported lost', new Origin(OriginKind::Web));
        $this->refused(Refusal::NoPasskey, static fn () => $passkeys->beginStepUp($session, $requirement));

        // A key whose registry entry is gone, as without RemovePasskeyWithCredential, is no passkey either.
        $this->stack->credentials->remove(new Realm('acme'), $credentials[1]->id);
        $this->refused(Refusal::NoPasskey, static fn () => $passkeys->beginStepUp($session, $requirement));
    }

    #[DataProvider('implementations')]
    public function testAStepUpForTwoFactorsAsksForUserVerification(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation, userVerification: UserVerification::Preferred);
        $session = $this->session();
        $authenticator = new VirtualAuthenticator();
        $registration = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Key', new Origin(OriginKind::Web));

        $ceremony = $passkeys->beginStepUp($session, new AuthenticationRequirement(Duration::minutes(5), multiFactor: true));
        self::assertSame('required', self::json($ceremony->options)['userVerification']);
        $this->refused(Refusal::UserNotVerified, static fn () => $passkeys->finishStepUp($session, $ceremony->id, $authenticator->signIn($ceremony->options, userVerified: false), new Origin(OriginKind::Web)));

        $ceremony = $passkeys->beginStepUp($session, new AuthenticationRequirement(Duration::minutes(5)));
        self::assertSame('preferred', self::json($ceremony->options)['userVerification']);
        $token = $passkeys->finishStepUp($session, $ceremony->id, $authenticator->signIn($ceremony->options, userVerified: false), new Origin(OriginKind::Web));
        self::assertFalse($this->sessions->validate(new Realm('acme'), $token)?->authentication->factors[1]->multiFactor, 'Without user verification, a passkey is one factor.');
    }

    #[DataProvider('implementations')]
    public function testSignInsFromOneAddressAreLimited(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation, limits: new AttemptLimits(perAddress: new Limit(2, Duration::minutes(1))));

        for ($i = 0; $i < 2; ++$i) {
            $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishSignIn(new Realm('acme'), $passkeys->beginSignIn(new Realm('acme'))->id, '{}', new Origin(OriginKind::Web), address: '192.0.2.1'));
        }

        try {
            $passkeys->finishSignIn(new Realm('acme'), $passkeys->beginSignIn(new Realm('acme'))->id, '{}', new Origin(OriginKind::Web), address: '192.0.2.1');
            self::fail('A third sign-in from the address in a minute was checked.');
        } catch (Throttled $exception) {
            self::assertSame(30, $exception->seconds);
        }

        $this->refused(Refusal::InvalidResponse, static fn () => $passkeys->finishSignIn(new Realm('acme'), $passkeys->beginSignIn(new Realm('acme'))->id, '{}', new Origin(OriginKind::Web), address: '192.0.2.2'));
    }

    #[DataProvider('implementations')]
    public function testTellsTheBrowserWhichPasskeysTheAccountHas(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $session = $this->session(email: 'ana@example.com', username: 'ana');
        $none = $passkeys->signals($session);
        self::assertNull($none, 'Without a passkey there\'s no user handle to signal for.');
        $phone = new VirtualAuthenticator();
        $laptop = new VirtualAuthenticator();

        foreach ([$phone, $laptop] as $authenticator) {
            $ceremony = $passkeys->beginRegistration($session);
            $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Passkey', new Origin(OriginKind::Web));
        }

        $signals = $passkeys->signals($session);
        self::assertNotNull($signals);
        self::assertSame([
            'rpId' => 'example.com',
            'userId' => VirtualAuthenticator::encode($phone->credentials[0]['userHandle']),
            'allAcceptedCredentialIds' => [VirtualAuthenticator::encode($phone->credentials[0]['id']), VirtualAuthenticator::encode($laptop->credentials[0]['id'])],
            'name' => 'ana@example.com',
            'displayName' => 'ana@example.com',
        ], $signals->toArray());

        $other = $this->session(username: 'bob');
        $ceremony = $passkeys->beginRegistration($other);
        $passkeys->finishRegistration($other, $ceremony->id, new VirtualAuthenticator()->register($ceremony->options), 'Passkey', new Origin(OriginKind::Web));
        $bobs = $passkeys->signals($other);
        self::assertNotNull($bobs);
        self::assertSame('bob', $bobs->name);
        self::assertCount(1, $bobs->credentialIds);
    }

    #[DataProvider('implementations')]
    public function testPurgesExpiredCeremonies(string $implementation): void
    {
        $passkeys = $this->passkeys($implementation);
        $passkeys->beginSignIn(new Realm('acme'));
        $this->clock->sleep(299);
        $passkeys->beginSignIn(new Realm('globex'));
        $this->clock->sleep(1);

        self::assertSame(1, $passkeys->purge());
    }

    private function refused(Refusal $refusal, \Closure $attempt, string $message = ''): void
    {
        try {
            $attempt();
            self::fail('' === $message ? \sprintf('Expected a refusal: %s.', $refusal->value) : $message);
        } catch (PasskeyRefused $exception) {
            self::assertSame($refusal, $exception->refusal, $message);
            self::assertSame($refusal->message(), $exception->getMessage());
        }
    }

    private function passkeys(string $implementation, UserVerification $userVerification = UserVerification::Required, ClonePolicy $clonePolicy = ClonePolicy::RefuseSignIn, AttemptLimits $limits = new AttemptLimits()): Passkeys
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->stepUp = new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock);
        $this->credentials = new Credentials($this->stack->credentials, $this->sessions, $this->stepUp, $log, $this->stack->transactions, $this->events, $ids, $this->clock);

        return new Passkeys($this->stack->passkeys, $this->stack->ceremonies, $this->credentials, $this->sessions, $this->principals, $log, $this->stack->transactions, new PasskeyPolicy('example.com', ['https://example.com'], $userVerification, $clonePolicy), new Attempts(new Throttle($this->stack->throttle, $this->clock), $limits), $ids, $this->clock);
    }

    private function session(?string $email = null, ?string $username = null): Session
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: $email, username: $username), new Origin(OriginKind::Console));

        return $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, $this->clock->now()))), new Origin(OriginKind::Web))->session;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $json): array
    {
        $decoded = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY);
    }
}
