<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\AddCredential;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;
use YorvoID\Throttle\Throttled;

/**
 * Passkeys (ATN-001…003, ATN-005, ATN-006): registering them, signing in with them, listing them.
 * WebAuthn's checks are web-auth/webauthn-lib's; YorvoID keeps the ceremonies, the policy and the
 * records.
 *
 * - Sign-in uses discoverable credentials: the browser offers the person's passkeys for the site,
 *   without a username first, also as autofill (conditional mediation).
 * - User verification is required by default, and YorvoID checks the authenticator's flag itself on
 *   every registration and sign-in, after the library, whatever the options said: the options are
 *   YorvoID's own and kept server-side, so a browser can't lower the requirement (the downgrade fixed
 *   in webauthn-lib 5.3.1, GHSA-h4fw-6r7f-w494, is tested against).
 * - A ceremony works once and expires; its challenge is 256 random bits.
 * - Registering needs a session that proved who it is recently (ATN-027).
 * - A step-up (ATN-072) proves it again with one of the session's principal's passkeys, bound to that
 *   session: the ceremony is the session's, and the proof is added to it.
 *
 * @api
 */
final readonly class Passkeys
{
    private SerializerInterface $serializer;

    public function __construct(
        private PasskeyStore $store,
        private CeremonyStore $ceremonyStore,
        private Credentials $credentials,
        private Sessions $sessions,
        private Principals $principals,
        private SecurityLog $log,
        private Transactions $transactions,
        private PasskeyPolicy $policy,
        private Attempts $attempts,
        private IdGenerator $ids = new UuidV7Generator(),
        private ClockInterface $clock = new NativeClock(),
    ) {
        // Only "none" attestation, the library's default: what matters is the passkey, not which device made
        // it (ATN-004 adds that).
        $this->serializer = new WebauthnSerializerFactory(new AttestationStatementSupportManager())->create();
    }

    /**
     * Begins adding a passkey to the session's principal.
     *
     * @throws StepUpRequired When the session didn't prove who it is recently
     */
    public function beginRegistration(Session $session): BegunCeremony
    {
        $this->credentials->requireStepUp($session, 'yorvoid.credential.add');
        $principal = $this->principals->get($session->realm, $session->principalId) ?? throw new UnknownPrincipal($session->realm, $session->principalId);
        $name = $this->userName($principal->realm, $principal->id);
        $existing = $this->store->of($principal->realm, $principal->id);

        $options = PublicKeyCredentialCreationOptions::create(
            // WebAuthn Level 3 dropped the name: browsers show the ID.
            PublicKeyCredentialRpEntity::create('', $this->policy->relyingPartyId),
            // One user handle per principal, random: it's stored on authenticators, so it says nothing about them.
            PublicKeyCredentialUserEntity::create($name, ([] === $existing ? null : $existing[0]->userHandle) ?? random_bytes(32), $name),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(ES256::ID), PublicKeyCredentialParameters::createPk(Ed25519::ID), PublicKeyCredentialParameters::createPk(RS256::ID)],
            AuthenticatorSelectionCriteria::create(userVerification: $this->policy->userVerification->value, residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(static fn (Passkey $passkey): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $passkey->webauthnId, $passkey->transports), $existing),
            $this->policy->timeoutMilliseconds,
        );

        return $this->begin($session->realm, CeremonyKind::Registration, $options, $session);
    }

    /**
     * Finishes adding a passkey: checks the browser's answer, and adds the passkey with its label.
     *
     * @param string $response The browser's PublicKeyCredential, as JSON
     * @param string $label    What its owner calls it, e.g. "MacBook"
     *
     * @throws PasskeyRefused
     * @throws StepUpRequired
     */
    public function finishRegistration(Session $session, string $ceremonyId, string $response, string $label, Origin $origin): Credential
    {
        $ceremony = $this->takeForSession($session, $ceremonyId, CeremonyKind::Registration);

        $options = $this->serializer->deserialize(self::withoutRelyingPartyName($ceremony->options), PublicKeyCredentialCreationOptions::class, 'json');
        $credential = $this->credential($response);
        $answer = $credential->response;

        if (!$answer instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyRefused(Refusal::InvalidResponse);
        }

        // Checked first, so the refusal says why; refusing on what isn't verified yet can't let anything in.
        $this->requireUserVerification($answer->attestationObject->authData->isUserVerified());

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->steps(new CounterSuspicion())->creationCeremony())->check($answer, $options, $this->policy->relyingPartyId);
        } catch (\Throwable $exception) {
            throw new PasskeyRefused(Refusal::InvalidResponse, $exception);
        }

        return $this->transactions->run(function () use ($session, $record, $label, $origin): Credential {
            $credential = $this->credentials->add(new AddCredential($session->realm, CredentialKind::Passkey, $label), $session, $origin);
            $passkey = new Passkey($credential->id, $session->realm, $session->principalId, $record->publicKeyCredentialId, $record->credentialPublicKey, $record->userHandle, $record->counter, $record->transports, $record->aaguid, true === $record->backupEligible, true === $record->backupStatus, true === $record->uvInitialized, $record->attestationType);

            // The store refuses an authenticator's ID that's registered already; the credential is rolled back.
            if (!$this->store->add($passkey)) {
                throw new PasskeyRefused(Refusal::AlreadyRegistered);
            }

            return $credential;
        });
    }

    /**
     * Begins signing in: the browser offers the person's passkeys for the site.
     */
    public function beginSignIn(Realm $realm): BegunCeremony
    {
        return $this->begin($realm, CeremonyKind::SignIn, PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->policy->relyingPartyId,
            [],
            $this->policy->userVerification->value,
            $this->policy->timeoutMilliseconds,
        ));
    }

    /**
     * Finishes signing in: checks the browser's answer, and starts a session.
     *
     * @param string      $response     The browser's PublicKeyCredential, as JSON
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession; pass it, so attempts from one address are limited
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     *
     * @throws PasskeyRefused
     * @throws Throttled      When there were too many attempts from the address; nothing was checked
     */
    public function finishSignIn(Realm $realm, string $ceremonyId, string $response, Origin $origin, ?string $device = null, ?string $address = null, #[\SensitiveParameter] ?Secret $deviceCookie = null): StartedSession
    {
        $this->attempts->count('passkey_sign_in', $realm, null, $address);
        $ceremony = $this->take($realm, $ceremonyId, CeremonyKind::SignIn);
        [$passkey, $credential, $record, $userVerified, $counter] = $this->verify($ceremony, $response, $origin, 'yorvoid.passkey.sign_in');

        return $this->transactions->run(function () use ($passkey, $credential, $record, $userVerified, $counter, $origin, $device, $address, $deviceCookie): StartedSession {
            $this->recordUse($passkey, $credential, $record, $counter, $origin);

            try {
                return $this->sessions->start(new StartSession($passkey->realm, $passkey->principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), $userVerified, true)), $device, $address, $deviceCookie), $origin);
            } catch (InactivePrincipal $exception) {
                throw new PasskeyRefused(Refusal::PrincipalInactive, $exception);
            }
        });
    }

    /**
     * Begins a step-up (ATN-072): the session's principal confirms it's them with one of their passkeys,
     * for what a refusal asked, e.g. StepUpRequired::$requirement. A requirement of two factors asks the
     * authenticator for user verification whatever the policy.
     *
     * @throws PasskeyRefused When the principal has no passkey that works
     */
    public function beginStepUp(Session $session, AuthenticationRequirement $requirement): BegunCeremony
    {
        $passkeys = array_filter($this->store->of($session->realm, $session->principalId), fn (Passkey $passkey): bool => true === $this->credentials->get($passkey->realm, $passkey->credentialId)?->isActive());

        if ([] === $passkeys) {
            throw new PasskeyRefused(Refusal::NoPasskey);
        }

        return $this->begin($session->realm, CeremonyKind::StepUp, PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->policy->relyingPartyId,
            array_map(static fn (Passkey $passkey): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $passkey->webauthnId, $passkey->transports), $passkeys),
            ($requirement->multiFactor ? UserVerification::Required : $this->policy->userVerification)->value,
            $this->policy->timeoutMilliseconds,
        ), $session, $requirement->operation);
    }

    /**
     * Finishes a step-up: checks the browser's answer, which must come from one of the session's
     * principal's passkeys, adds the proof to the session for the requirement's operation, and gives the
     * session a new token.
     *
     * @param string $response The browser's PublicKeyCredential, as JSON
     *
     * @return Secret The session's new token; the old one stops working
     *
     * @throws PasskeyRefused
     */
    public function finishStepUp(Session $session, string $ceremonyId, string $response, Origin $origin): Secret
    {
        $ceremony = $this->takeForSession($session, $ceremonyId, CeremonyKind::StepUp);
        [$passkey, $credential, $record, $userVerified, $counter] = $this->verify($ceremony, $response, $origin, 'yorvoid.passkey.step_up', $session->principalId);

        return $this->transactions->run(function () use ($session, $ceremony, $passkey, $credential, $record, $userVerified, $counter, $origin): Secret {
            $this->recordUse($passkey, $credential, $record, $counter, $origin);
            $factor = new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), $userVerified, true, $ceremony->operation);

            // The session ended, or its principal was suspended, while the person was at their authenticator.
            return $this->sessions->stepUp($session->realm, $session->id, $factor, $origin) ?? throw new PasskeyRefused(Refusal::CeremonyExpired);
        });
    }

    /**
     * A principal's passkeys; their labels, last use and status are in the credential registry.
     *
     * @return list<Passkey>
     */
    public function of(Realm $realm, PrincipalId $principalId): array
    {
        return $this->store->of($realm, $principalId);
    }

    /**
     * What the browser tells the person's passkey provider about the session's account (ATN-008): null
     * if it has no passkeys yet, so there's nothing to tell.
     */
    public function signals(Session $session): ?PasskeySignals
    {
        $passkeys = $this->store->of($session->realm, $session->principalId);

        if ([] === $passkeys) {
            return null;
        }

        return new PasskeySignals(
            $this->policy->relyingPartyId,
            self::base64Url($passkeys[0]->userHandle),
            array_map(static fn (Passkey $passkey): string => self::base64Url($passkey->webauthnId), $passkeys),
            $this->userName($session->realm, $session->principalId),
        );
    }

    /**
     * The parameters of the intent verifier's "add key" operation for one of the principal's passkeys
     * (OperationPolicy::ADD_KEY): prepare it with them, at the verifier, to let the passkey sign
     * operations there.
     *
     * @param string $label What the verifier calls it, e.g. "Ana's YubiKey"
     *
     * @return array{credential_id: string, public_key: string, user_handle: string, label: string}
     */
    public static function verifierKey(Passkey $passkey, string $label): array
    {
        return ['credential_id' => self::base64Url($passkey->webauthnId), 'public_key' => self::base64Url($passkey->publicKey), 'user_handle' => self::base64Url($passkey->userHandle), 'label' => $label];
    }

    /**
     * Deletes expired ceremonies; run it daily, e.g. from a scheduled job.
     */
    public function purge(): int
    {
        return $this->ceremonyStore->purge($this->clock->now());
    }

    private function begin(Realm $realm, CeremonyKind $kind, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options, ?Session $session = null, ?string $operation = null): BegunCeremony
    {
        $json = $this->serializer->serialize($options, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
        $ceremony = new Ceremony(Uuid::fromString((string) $this->ids->next()), $realm, $kind, $json, $this->policy->timeout->after(Time::stored($this->clock->now())), $session?->id, $operation);
        $this->ceremonyStore->add($ceremony);

        return new BegunCeremony((string) $ceremony->id, $json);
    }

    private function take(Realm $realm, string $id, CeremonyKind $kind): Ceremony
    {
        $ceremony = Uuid::isValid($id) ? $this->transactions->run(fn (): ?Ceremony => $this->ceremonyStore->take($realm, Uuid::fromString($id))) : null;

        if (null === $ceremony || $kind !== $ceremony->kind || $this->clock->now() >= $ceremony->expiresAt) {
            throw new PasskeyRefused(Refusal::CeremonyExpired);
        }

        return $ceremony;
    }

    /**
     * A ceremony that the session began: another session can't finish it.
     */
    private function takeForSession(Session $session, string $id, CeremonyKind $kind): Ceremony
    {
        $ceremony = $this->take($session->realm, $id, $kind);

        if (null === $ceremony->sessionId || !$ceremony->sessionId->equals($session->id)) {
            throw new PasskeyRefused(Refusal::CeremonyExpired);
        }

        return $ceremony;
    }

    /**
     * Checks an answer to a sign-in or step-up ceremony: a valid assertion of an active passkey, of the
     * principal if one is expected, with user verification when the policy or the ceremony requires it.
     *
     * @param string $action What a refusal is recorded as, e.g. "yorvoid.passkey.sign_in"
     *
     * @return array{Passkey, Credential, CredentialRecord, bool, CounterSuspicion} The passkey, its credential, what
     *                                                                              the library checked, whether the user was verified, and whether the passkey may be a copy
     */
    private function verify(Ceremony $ceremony, string $response, Origin $origin, string $action, ?PrincipalId $principalId = null): array
    {
        $options = $this->serializer->deserialize($ceremony->options, PublicKeyCredentialRequestOptions::class, 'json');
        $answer = $this->credential($response);
        $assertion = $answer->response;

        if (!$assertion instanceof AuthenticatorAssertionResponse) {
            throw new PasskeyRefused(Refusal::InvalidResponse);
        }

        $passkey = $this->store->find($ceremony->realm, $answer->rawId);
        $credential = null === $passkey ? null : $this->credentials->get($ceremony->realm, $passkey->credentialId);

        // Someone else's passkey is as unknown here as one that doesn't exist.
        if (null === $passkey || null === $credential || (null !== $principalId && !$passkey->principalId->equals($principalId))) {
            throw new PasskeyRefused(Refusal::UnknownPasskey);
        }

        if (!$credential->isActive()) {
            $this->refuse($passkey, $action, Refusal::PasskeyDisabled, $origin);
        }

        $userVerified = $assertion->authenticatorData->isUserVerified();

        // The options are YorvoID's own, kept server-side; the policy applies whatever they say.
        if (!$userVerified && (UserVerification::Required === $this->policy->userVerification || UserVerification::Required->value === $options->userVerification)) {
            $this->refuse($passkey, $action, Refusal::UserNotVerified, $origin);
        }

        $counter = new CounterSuspicion();

        // No user handle given: the answer must name the passkey's own.
        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->steps($counter)->requestCeremony())->check($passkey->record(), $assertion, $options, $this->policy->relyingPartyId, null);
        } catch (\Throwable $exception) {
            $this->refuse($passkey, $action, Refusal::InvalidResponse, $origin, $exception);
        }

        if ($counter->suspected && ClonePolicy::Allow !== $this->policy->clonePolicy) {
            if (ClonePolicy::DisablePasskey === $this->policy->clonePolicy) {
                $this->credentials->disable($credential, 'signature counter didn\'t go up: the passkey may have been copied', $origin);
            }

            $this->refuse($passkey, $action, Refusal::SuspectedCopy, $origin);
        }

        return [$passkey, $credential, $record, $userVerified, $counter];
    }

    /**
     * Records a passkey's use: its new counter and backup state, and when it was last used.
     */
    private function recordUse(Passkey $passkey, Credential $credential, CredentialRecord $record, CounterSuspicion $counter, Origin $origin): void
    {
        $this->store->update(new Passkey($passkey->credentialId, $passkey->realm, $passkey->principalId, $passkey->webauthnId, $passkey->publicKey, $passkey->userHandle, $record->counter, $passkey->transports, $passkey->aaguid, true === $record->backupEligible, true === $record->backupStatus, true === $record->uvInitialized, $passkey->attestationType));
        $this->credentials->markUsed($credential);

        if ($counter->suspected) {
            $this->log->record($passkey->realm, 'yorvoid.passkey.suspected_copy', Outcome::Success, $origin, $passkey->principalId, 'credential', (string) $passkey->credentialId, ['principal' => (string) $passkey->principalId]);
        }
    }

    /**
     * The browser's answer, whatever it sent: anything that isn't a valid PublicKeyCredential is refused.
     */
    private function credential(string $response): PublicKeyCredential
    {
        try {
            return $this->serializer->deserialize($response, PublicKeyCredential::class, 'json');
        } catch (\Throwable $exception) {
            throw new PasskeyRefused(Refusal::InvalidResponse, $exception);
        }
    }

    /**
     * The library writes rp.name as the relying party's ID, and deprecates reading it back: the options
     * are read without it, which the checks don't use.
     */
    private static function withoutRelyingPartyName(string $options): string
    {
        $decoded = json_decode($options, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded) && \is_array($decoded['rp']));
        $decoded['rp']['name'] = '';

        return json_encode($decoded, \JSON_THROW_ON_ERROR);
    }

    /**
     * The WebAuthn checks, with the policy's origins and algorithms, and the counter noted rather than refused.
     */
    private function steps(CounterSuspicion $counter): CeremonyStepManagerFactory
    {
        $steps = new CeremonyStepManagerFactory();
        $steps->setAllowedOrigins($this->policy->origins);
        $steps->setAlgorithmManager(Manager::create()->add(ES256::create(), Ed25519::create(), RS256::create()));
        $steps->setCounterChecker($counter);

        return $steps;
    }

    private function requireUserVerification(bool $verified): void
    {
        if (!$verified && UserVerification::Required === $this->policy->userVerification) {
            throw new PasskeyRefused(Refusal::UserNotVerified);
        }
    }

    private function refuse(Passkey $passkey, string $action, Refusal $refusal, Origin $origin, ?\Throwable $previous = null): never
    {
        $this->log->record($passkey->realm, $action, Outcome::Failed, $origin, null, 'credential', (string) $passkey->credentialId, [
            'principal' => (string) $passkey->principalId,
            'reason' => $refusal->value,
        ]);

        throw new PasskeyRefused($refusal, $previous);
    }

    /**
     * The name passkeys are shown with: the email address, which people recognise, before the username.
     */
    private function userName(Realm $realm, PrincipalId $principalId): string
    {
        $identifiers = $this->principals->identifiers($realm, $principalId);

        return ($identifiers[IdentifierKind::Email->value] ?? $identifiers[IdentifierKind::Username->value] ?? null)->value ?? (string) $principalId;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
