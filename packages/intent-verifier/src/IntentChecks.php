<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * The checks of what the browser answers (A-7): an intent's nonce taken once, an assertion over its
 * challenge by one of the given keys, and a registration's attestation for the signing relying party.
 * What's refused is recorded and thrown.
 *
 * @internal Verifier's; its API is Verifier's
 */
final readonly class IntentChecks
{
    /** A nonce as Verifier makes it: 18 random bytes, base64url */
    private const string NONCE = '/^[A-Za-z0-9_-]{24}$/D';

    public function __construct(
        private VerifierConfig $config,
        private VerifierLog $log,
        private ClockInterface $clock,
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * Takes the intent waiting under the nonce, using the nonce up, if it's of the kind expected.
     *
     * @param \Closure(PendingIntent): bool $expected
     *
     * @throws IntentRefused
     */
    public function take(VerifierState $state, string $nonce, \Closure $expected): PendingIntent
    {
        // Not one the verifier made: recorded by a short hash, whatever its size or encoding (FUZZ-08).
        if (1 !== preg_match(self::NONCE, $nonce)) {
            $this->log->record('refused', ['nonce_hash' => substr(Digest::sha256($nonce), 0, 16), 'reason' => Refusal::UnknownIntent->value], $this->clock->now());

            throw new IntentRefused(Refusal::UnknownIntent);
        }

        $pending = $state->pending[$nonce] ?? null;
        unset($state->pending[$nonce]);

        // The log has the nonce used already: the state was restored from an older copy (INTENTS-07).
        if (null !== $pending && $this->log->usedNonce($nonce, $pending->intent->issuedAt)) {
            $pending = null;
        }

        if (null === $pending || $this->clock->now() >= $pending->intent->expiresAt) {
            $this->log->record('refused', ['nonce' => $nonce, 'reason' => Refusal::UnknownIntent->value], $this->clock->now());

            throw new IntentRefused(Refusal::UnknownIntent);
        }

        if (!$expected($pending)) {
            $this->refuse($pending->intent, Refusal::WrongOperation);
        }

        return $pending;
    }

    /**
     * Checks the assertion over the intent's challenge with one of the keys, and records the key's new
     * signature counter.
     *
     * @param array<string, Key> $keys By ID
     *
     * @throws IntentRefused
     */
    public function check(VerifierState $state, PendingIntent $pending, array $keys, string $assertion): VerifiedIntent
    {
        try {
            $credential = Answer::assertion($assertion);
        } catch (\Throwable $exception) {
            $this->refuse($pending->intent, Refusal::InvalidSignature, $exception);
        }

        $response = $credential->response;

        if (!$response instanceof AuthenticatorAssertionResponse || !$this->isOwnClientData($response->clientDataJSON, 'webauthn.get')) {
            $this->refuse($pending->intent, Refusal::InvalidSignature);
        }

        $key = $keys[Base64Url::encode($credential->rawId)] ?? null;

        if (null === $key) {
            $this->refuse($pending->intent, Refusal::UnknownKey);
        }

        // Required whatever an answer says, and checked before the library's other checks.
        if (!$response->authenticatorData->isUserVerified()) {
            $this->refuse($pending->intent, Refusal::UserNotVerified);
        }

        // The key is known before the ceremony, so its user handle is given: signing passkeys aren't
        // discoverable, and browsers leave the user handle out of their answers.
        $options = PublicKeyCredentialRequestOptions::create($pending->intent->challenge(), $this->config->relyingPartyId, [], PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED);
        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->steps()->requestCeremony())->check(self::credentialRecord($key), $response, $options, $this->config->relyingPartyId, $key->userHandle);
        } catch (\Throwable $exception) {
            $this->refuse($pending->intent, Refusal::InvalidSignature, $exception);
        }

        $used = $key->withSignCount($record->counter);

        if (isset($state->keys[$key->id()])) {
            $state->keys[$key->id()] = $used;
        }

        return new VerifiedIntent($pending->intent, $used, $this->clock->now());
    }

    /**
     * The key the authenticator registered, if its answer is a valid one to the registration: user
     * verified, at a signing origin, for the signing relying party.
     *
     * @throws IntentRefused
     */
    public function registered(PendingRegistration $pending, string $attestation): Key
    {
        try {
            $credential = Answer::attestation($attestation, new AttestationStatementSupportManager());
            // The library writes the relying party's name as its ID, and warns when it reads one back.
            $stored = json_decode($pending->options, true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($stored) && \is_array($stored['rp']));
            $stored['rp']['name'] = '';
            $options = $this->serializer->deserialize(json_encode($stored, \JSON_THROW_ON_ERROR), PublicKeyCredentialCreationOptions::class, 'json');
        } catch (\Throwable $exception) {
            throw new IntentRefused(Refusal::InvalidRegistration, $exception);
        }

        $response = $credential->response;

        if (!$response instanceof AuthenticatorAttestationResponse || !$this->isOwnClientData($response->clientDataJSON, 'webauthn.create')) {
            throw new IntentRefused(Refusal::InvalidRegistration);
        }

        if (!$response->attestationObject->authData->isUserVerified()) {
            throw new IntentRefused(Refusal::UserNotVerified);
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->steps()->creationCeremony())->check($response, $options, $this->config->relyingPartyId);
        } catch (\Throwable $exception) {
            throw new IntentRefused(Refusal::InvalidRegistration, $exception);
        }

        $key = Key::fromParameters(['credential_id' => Base64Url::encode($record->publicKeyCredentialId), 'public_key' => Base64Url::encode($record->credentialPublicKey), 'user_handle' => Base64Url::encode($record->userHandle), 'label' => $pending->label], new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));

        return $key ?? throw new IntentRefused(Refusal::InvalidKey);
    }

    /**
     * Records the refusal and throws it.
     *
     * @throws IntentRefused
     */
    public function refuse(Intent $intent, Refusal $refusal, ?\Throwable $previous = null): never
    {
        $this->log->record('refused', ['action' => $intent->action, 'nonce' => $intent->nonce, 'reason' => $refusal->value], $this->clock->now());

        throw new IntentRefused($refusal, $previous);
    }

    /**
     * What the library leaves (INTENTS-08): the answer is for this kind of ceremony, not from a frame,
     * and from one of the signing origins exactly as configured, byte for byte.
     */
    private function isOwnClientData(CollectedClientData $clientData, string $type): bool
    {
        return $type === $clientData->type && !$clientData->crossOrigin && null === $clientData->topOrigin && \in_array($clientData->origin, array_map(static fn (string $origin): string => rtrim($origin, '/'), $this->config->origins), true);
    }

    private function steps(): CeremonyStepManagerFactory
    {
        $steps = new CeremonyStepManagerFactory();
        // Deliberate defence in depth: isOwnClientData() checks the origin first, so this second check
        // can't be told apart by mutation testing (infection.json5), but stays.
        $steps->setAllowedOrigins($this->config->origins);
        $steps->setAlgorithmManager(Manager::create()->add(ES256::create(), Ed25519::create(), RS256::create()));

        return $steps;
    }

    private static function credentialRecord(Key $key): CredentialRecord
    {
        return CredentialRecord::create($key->credentialId, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), $key->publicKey, $key->userHandle, $key->signCount);
    }
}
