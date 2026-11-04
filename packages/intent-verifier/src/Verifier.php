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
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Signed intents, checked where the operation runs (D-11, INT-001…010, INT-015, INT-016): the verifier
 * runs a listed operation only after one of its own keys signed exactly that operation.
 *
 * 1. prepare() takes the operation the console asks for, checks it against the policy, gives it a nonce
 *    and keeps it: the console gets the WebAuthn options to ask the browser for a signature.
 * 2. verify() takes the nonce and the browser's assertion: the challenge comes from the intent the
 *    verifier kept, so the console can't change the operation after it's prepared (T-INT-1). The
 *    nonce works once, signed or not, and expires (INT-003, T-INT-4).
 *
 * Its keys are passkeys of its own relying party, registered at its signing origin
 * (prepareRegistration()), never one of the console's. They change only by intents: a key is added with
 * a signature by an existing key, removed
 * with a signature, never the last one. Each change waits a day (by default) before it takes effect,
 * while the owners are told and any key can cancel it (INT-013, INT-017). The first key comes with the
 * bootstrap secret, signed by itself, at once; a new secret is the host's way back in when every key is
 * lost, and waits like any change (INT-005, 006, 016).
 *
 * Every operation verified and every change to its keys goes to its notifier, for the owners, through
 * a channel the console doesn't control (INT-012).
 *
 * It trusts nothing the console says: it needs no framework, no database, and none of YorvoID's other
 * packages (INT-007). What the console relays from the browser is read by hand into a fixed shape
 * (Answer) before the WebAuthn library's byte-level checks see it; the library's serializer handles
 * only the verifier's own options. Its trusted base is that library and what it requires
 * (docs/security/dependencies.md).
 *
 * @api
 */
final readonly class Verifier
{
    /** How many intents and registrations may wait at once: each is read and written with the whole state */
    public const int MAX_WAITING = 1000;

    /** A nonce as begin() makes it: 18 random bytes, base64url */
    private const string NONCE = '/^[A-Za-z0-9_-]{24}$/D';

    private SerializerInterface $serializer;

    public function __construct(
        private VerifierConfig $config,
        private OperationPolicy $policy,
        private VerifierStore $store,
        private VerifierLog $log,
        private VerifierNotifier $notifier,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->serializer = new WebauthnSerializerFactory(new AttestationStatementSupportManager())->create();
    }

    /**
     * Prepares an operation for signing by one of the verifier's keys.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws ConfigurationError When the operation isn't listed, or its parameters aren't its own
     * @throws IntentRefused      When MAX_WAITING intents and registrations wait already (Refusal::TooManyWaiting)
     */
    public function prepare(string $action, array $parameters): PreparedIntent
    {
        $operation = $this->policy->get($action);

        if (!$operation->signed) {
            throw new ConfigurationError(\sprintf('The operation %s runs unsigned: there\'s nothing to prepare.', $action));
        }

        if (OperationPolicy::ADD_KEY === $action) {
            throw new ConfigurationError('A key is added from its registration at the signing origin: use prepareRegistration().');
        }

        return $this->begin($operation, $parameters, false);
    }

    /**
     * Starts registering a passkey at the signing origin, for its relying party: give the options to
     * navigator.credentials.create() on the signing page, then the answer to finishRegistration().
     *
     * @param string $label     What the owner calls it, e.g. "Ana's YubiKey"
     * @param bool   $bootstrap Whether it's added with the bootstrap secret, signing its own addition; otherwise one
     *                          of the verifier's keys signs it
     *
     * @throws ConfigurationError When the label is empty, longer than 64 characters, or has characters that can't be seen
     * @throws IntentRefused      When MAX_WAITING intents and registrations wait already (Refusal::TooManyWaiting)
     */
    public function prepareRegistration(string $label, bool $bootstrap = false): PendingRegistration
    {
        $label = trim($label);

        if ('' === $label || mb_strlen($label) > 64 || !ParameterType::String->accepts($label)) {
            throw new ConfigurationError('A key\'s label has 1 to 64 characters, all of them visible.');
        }

        return $this->transaction(function (VerifierState $state) use ($label, $bootstrap): PendingRegistration {
            $this->makeRoom($state);
            $known = [...array_values($state->keys), ...array_map(static fn (KeyChange $change): Key => $change->key, array_values($state->keyChanges))];
            $options = PublicKeyCredentialCreationOptions::create(
                PublicKeyCredentialRpEntity::create('', $this->config->relyingPartyId),
                PublicKeyCredentialUserEntity::create($label, random_bytes(32), $label),
                random_bytes(32),
                [PublicKeyCredentialParameters::createPk(ES256::ID), PublicKeyCredentialParameters::createPk(Ed25519::ID), PublicKeyCredentialParameters::createPk(RS256::ID)],
                AuthenticatorSelectionCriteria::create(userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED, residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED),
                PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
                array_map(static fn (Key $key): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $key->credentialId), $known),
                $this->config->intentLifetime * 1000,
            );
            $registration = new PendingRegistration(Base64Url::encode(random_bytes(18)), $this->serializer->serialize($options, 'json'), $label, $bootstrap, $this->clock->now()->modify(\sprintf('+%d seconds', $this->config->intentLifetime)));
            $state->registrations[$registration->id] = $registration;
            $this->log->record('registration_prepared', ['registration' => $registration->id, 'bootstrap' => $bootstrap], $this->clock->now());

            return $registration;
        });
    }

    /**
     * Checks the authenticator's answer to a registration, and prepares the new key's "add key" intent from
     * it: to be signed by one of the verifier's keys (addKey()), or for the bootstrap secret by the new key
     * itself (bootstrap()). The registration works once.
     *
     * @param string $attestation The browser's PublicKeyCredential, as JSON
     *
     * @throws IntentRefused
     */
    public function finishRegistration(string $registration, string $attestation): PreparedIntent
    {
        $registered = $this->transaction(function (VerifierState $state) use ($registration, $attestation): array|IntentRefused {
            $pending = $state->registrations[$registration] ?? null;
            unset($state->registrations[$registration]);

            try {
                if (null === $pending || $this->clock->now() >= $pending->expiresAt) {
                    throw new IntentRefused(Refusal::InvalidRegistration);
                }

                $key = $this->registered($pending, $attestation);
                $this->log->record('registered', ['registration' => $registration, 'subject' => $key->id(), 'algorithm' => $key->algorithm], $this->clock->now());

                return [$key, $pending->bootstrap];
            } catch (IntentRefused $refusal) {
                $this->log->record('refused', ['registration' => $registration, 'reason' => $refusal->refusal->value], $this->clock->now());

                return $refusal;
            }
        });

        if ($registered instanceof IntentRefused) {
            throw $registered;
        }

        [$key, $bootstrap] = $registered;
        $parameters = ['credential_id' => $key->id(), 'public_key' => Base64Url::encode($key->publicKey), 'user_handle' => Base64Url::encode($key->userHandle), 'label' => $key->label];

        return $this->begin($this->policy->get(OperationPolicy::ADD_KEY), $parameters, $bootstrap);
    }

    /**
     * Checks the signature of a prepared operation. Run the operation only after this returns.
     *
     * @param string $assertion The browser's PublicKeyCredential, as JSON
     *
     * @throws IntentRefused
     */
    public function verify(string $nonce, string $assertion): VerifiedIntent
    {
        $verified = $this->attempt(function (VerifierState $state) use ($nonce, $assertion): VerifiedIntent {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => !$pending->bootstrap && !str_starts_with($pending->intent->action, 'yorvoid.verifier.'));
            $verified = $this->check($state, $pending, $state->keys, $assertion);
            $this->record('verified', $verified);

            return $verified;
        });
        $this->notifier->notify(new VerifierNotice(VerifierNotice::VERIFIED, $verified->intent, $verified->key, $verified->verifiedAt));

        return $verified;
    }

    /**
     * Asks to add the key a prepared "add key" intent describes, signed by one of the verifier's keys. It
     * takes effect after the configured delay, unless a key cancels it.
     *
     * @throws IntentRefused
     */
    public function addKey(string $nonce, string $assertion): KeyChange
    {
        [$change, $intent] = $this->attempt(function (VerifierState $state) use ($nonce, $assertion): array {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => !$pending->bootstrap && OperationPolicy::ADD_KEY === $pending->intent->action);
            $key = $this->newKey($state, $pending->intent);
            $verified = $this->check($state, $pending, $state->keys, $assertion);

            return [$this->requestChange($state, KeyChange::ADD, $key, $verified), $verified->intent];
        });

        return $this->requested($change, $intent);
    }

    /**
     * Asks to remove the key a prepared "remove key" intent names, signed by one of the verifier's keys;
     * never the last one. It takes effect after the configured delay, unless a key cancels it: until then,
     * the key still signs.
     *
     * @throws IntentRefused
     */
    public function removeKey(string $nonce, string $assertion): KeyChange
    {
        [$change, $intent] = $this->attempt(function (VerifierState $state) use ($nonce, $assertion): array {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => OperationPolicy::REMOVE_KEY === $pending->intent->action);
            $verified = $this->check($state, $pending, $state->keys, $assertion);
            $removed = $pending->intent->parameters['credential_id'];
            $leaving = array_map(static fn (KeyChange $change): string => $change->key->id(), array_filter($state->keyChanges, static fn (KeyChange $change): bool => KeyChange::REMOVE === $change->kind));
            $key = \is_string($removed) && isset($state->keys[$removed]) && !\in_array($removed, $leaving, true) ? $state->keys[$removed] : $this->refuse($pending->intent, Refusal::UnknownKey);

            if (\count($state->keys) - \count($leaving) <= 1) {
                $this->refuse($pending->intent, Refusal::LastKey);
            }

            return [$this->requestChange($state, KeyChange::REMOVE, $key, $verified), $verified->intent];
        });

        return $this->requested($change, $intent);
    }

    /**
     * Cancels a change to the keys before it takes effect, signed by one of the verifier's keys: prepare
     * OperationPolicy::CANCEL_KEY_CHANGE with the change's ID.
     *
     * @throws IntentRefused
     */
    public function cancelKeyChange(string $nonce, string $assertion): KeyChange
    {
        [$change, $verified] = $this->attempt(function (VerifierState $state) use ($nonce, $assertion): array {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => OperationPolicy::CANCEL_KEY_CHANGE === $pending->intent->action);
            $verified = $this->check($state, $pending, $state->keys, $assertion);
            $id = $pending->intent->parameters['change'];
            $change = \is_string($id) && isset($state->keyChanges[$id]) ? $state->keyChanges[$id] : $this->refuse($pending->intent, Refusal::UnknownKeyChange);
            unset($state->keyChanges[$change->id]);
            $this->record('key_change_cancelled', $verified, ['change' => $change->id]);

            return [$change, $verified];
        });
        $this->notifier->notify(new VerifierNotice(VerifierNotice::KEY_CHANGE_CANCELLED, $verified->intent, $verified->key, $verified->verifiedAt, $change));

        return $change;
    }

    /**
     * Applies the changes to the keys that are due, and tells the notifier. Every other call does it
     * first, in its own transaction; run it on a schedule too (e.g. hourly), so a change takes effect,
     * and is announced, on time.
     *
     * @return int How many took effect
     */
    public function applyKeyChanges(): int
    {
        $this->transaction(static fn (VerifierState $state): null => null, $applied);

        return $applied;
    }

    /**
     * The changes to the keys waiting to take effect, in the order they were asked for, which is the order
     * they take effect in.
     *
     * @return list<KeyChange>
     *
     * @phpstan-impure
     */
    public function keyChanges(): array
    {
        return $this->transaction(static fn (VerifierState $state): array => array_values($state->keyChanges));
    }

    /**
     * Creates a bootstrap secret, replacing any earlier one: for the host's root (e.g. a console
     * command run by root), never for a web page. With it, the first key is added; when keys exist, it's
     * the way back in after every one was lost (INT-016). Recorded; tell every owner it happened.
     *
     * Type it only on the signing page: never give it to the console.
     *
     * @param int $lifetime How long, in seconds, it works: an hour by default, at most a day
     *
     * @return string The secret, shown only now
     */
    public function createBootstrapSecret(int $lifetime = 3600): string
    {
        if ($lifetime < 60 || $lifetime > 86400) {
            throw new ConfigurationError('A bootstrap secret works between a minute and a day.');
        }

        $secret = Base64Url::encode(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() + $lifetime));

        $this->transaction(function (VerifierState $state) use ($secret, $expiresAt): void {
            $state->bootstrapSecretHash = hash('sha256', $secret);
            $state->bootstrapSecretExpiresAt = $expiresAt;
            $this->log->record('bootstrap_secret_created', ['keys' => \count($state->keys), 'expires_at' => $expiresAt->getTimestamp()], $this->clock->now());
        });
        $this->notifier->notify(new VerifierNotice(VerifierNotice::BOOTSTRAP_SECRET_CREATED, null, null, $this->clock->now()));

        return $secret;
    }

    /**
     * Adds a key with the bootstrap secret: the key signed its own "add key" intent, prepared from its
     * registration (prepareRegistration() with $bootstrap, then finishRegistration()). The secret works once. The first key is added at once; when the verifier has
     * keys, the new one waits like any change, so their owners can cancel it.
     *
     * @throws IntentRefused
     */
    public function bootstrap(#[\SensitiveParameter] string $secret, string $nonce, string $assertion): KeyChange
    {
        [$change, $intent] = $this->attempt(function (VerifierState $state) use ($secret, $nonce, $assertion): array {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => $pending->bootstrap);

            // A secret and its expiry are set and cleared together.
            if (null === $state->bootstrapSecretExpiresAt || $this->clock->now() >= $state->bootstrapSecretExpiresAt || !hash_equals((string) $state->bootstrapSecretHash, hash('sha256', $secret))) {
                $this->refuse($pending->intent, Refusal::InvalidBootstrapSecret);
            }

            $key = $this->newKey($state, $pending->intent);
            $verified = $this->check($state, $pending, [$key->id() => $key], $assertion);
            $state->bootstrapSecretHash = null;
            $state->bootstrapSecretExpiresAt = null;

            return [$this->requestChange($state, KeyChange::ADD, $verified->key, $verified, [] === $state->keys), $verified->intent];
        });

        return $this->requested($change, $intent);
    }

    /**
     * The keys whose signatures the verifier accepts.
     *
     * @return list<Key>
     *
     * @phpstan-impure
     */
    public function keys(): array
    {
        return $this->transaction(static fn (VerifierState $state): array => array_values($state->keys));
    }

    /**
     * Forgets intents that expired unsigned, and registrations that expired unanswered; run it now and
     * then, e.g. hourly.
     *
     * @return int How many
     */
    public function purge(): int
    {
        $now = $this->clock->now();

        return $this->transaction(static function (VerifierState $state) use ($now): int {
            $count = \count($state->pending) + \count($state->registrations);
            $state->pending = array_filter($state->pending, static fn (PendingIntent $pending): bool => $now < $pending->intent->expiresAt);
            $state->registrations = array_filter($state->registrations, static fn (PendingRegistration $registration): bool => $now < $registration->expiresAt);

            return $count - \count($state->pending) - \count($state->registrations);
        });
    }

    /**
     * Runs work in a store transaction that first applies the changes to the keys that are due, so every
     * call sees the keys of now; then tells the notifier of them.
     *
     * @template T
     *
     * @param \Closure(VerifierState): T $work
     *
     * @param-out int                    $applied How many changes took effect
     *
     * @return T
     */
    private function transaction(\Closure $work, ?int &$applied = null): mixed
    {
        $now = $this->clock->now();
        [$result, $changes] = $this->store->transaction(function (VerifierState $state) use ($work, $now): array {
            $changes = $this->applyDue($state, $now);

            return [$work($state), $changes];
        });

        foreach ($changes as $change) {
            $this->notifier->notify(new VerifierNotice(KeyChange::ADD === $change->kind ? VerifierNotice::KEY_ADDED : VerifierNotice::KEY_REMOVED, null, $change->key, $now, $change));
        }

        $applied = \count($changes);

        return $result;
    }

    /**
     * @return list<KeyChange> The changes that took effect
     */
    private function applyDue(VerifierState $state, \DateTimeImmutable $now): array
    {
        $applied = [];

        // Asking for a change checked it against the keys and the changes before it: none can leave the
        // verifier without keys, nor add one twice.
        foreach (array_filter($state->keyChanges, static fn (KeyChange $change): bool => $now >= $change->effectiveAt) as $id => $change) {
            unset($state->keyChanges[$id]);

            if (KeyChange::ADD === $change->kind) {
                $state->keys[$change->key->id()] = $change->key;
            } else {
                unset($state->keys[$change->key->id()]);
            }

            $this->log->record(KeyChange::ADD === $change->kind ? 'key_added' : 'key_removed', ['change' => $change->id, 'key' => $change->key->id(), 'signer' => $change->signer], $now);
            $applied[] = $change;
        }

        return $applied;
    }

    /**
     * Runs the work in a store transaction that's saved even when the work refuses: a refused attempt
     * still uses its nonce up. Then throws the refusal, if any.
     *
     * @template T
     *
     * @param \Closure(VerifierState): T $work
     *
     * @return T
     *
     * @throws IntentRefused
     */
    private function attempt(\Closure $work): mixed
    {
        $result = $this->transaction(static function (VerifierState $state) use ($work): mixed {
            try {
                return $work($state);
            } catch (IntentRefused $refusal) {
                return $refusal;
            }
        });

        if ($result instanceof IntentRefused) {
            throw $result;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function begin(Operation $operation, array $parameters, bool $bootstrap): PreparedIntent
    {
        if (!$operation->accepts($parameters)) {
            throw new ConfigurationError(\sprintf('The parameters aren\'t those of %s: give exactly %s, of their types.', $operation->action, implode(', ', array_keys($operation->parameters))));
        }

        $now = $this->clock->now();
        $issuedAt = new \DateTimeImmutable('@'.$now->getTimestamp());
        $intent = new Intent($operation->action, $parameters, $this->config->audience, Base64Url::encode(random_bytes(18)), $issuedAt, $issuedAt->modify(\sprintf('+%d seconds', $this->config->intentLifetime)));
        $pending = new PendingIntent($intent, $bootstrap);

        $keys = $this->transaction(function (VerifierState $state) use ($pending): array {
            $this->makeRoom($state);
            $state->pending[$pending->intent->nonce] = $pending;
            $this->log->record('prepared', ['action' => $pending->intent->action, 'nonce' => $pending->intent->nonce, 'bootstrap' => $pending->bootstrap], $this->clock->now());

            return $state->keys;
        });

        return $this->ready($pending, $keys);
    }

    /**
     * Forgets the intents and registrations that expired; refuses another when MAX_WAITING still wait.
     *
     * @throws IntentRefused
     */
    private function makeRoom(VerifierState $state): void
    {
        $now = $this->clock->now();
        $state->pending = array_filter($state->pending, static fn (PendingIntent $pending): bool => $now < $pending->intent->expiresAt);
        $state->registrations = array_filter($state->registrations, static fn (PendingRegistration $registration): bool => $now < $registration->expiresAt);

        if (\count($state->pending) + \count($state->registrations) >= self::MAX_WAITING) {
            $this->log->record('refused', ['reason' => Refusal::TooManyWaiting->value], $now);

            throw new IntentRefused(Refusal::TooManyWaiting);
        }
    }

    /**
     * The intent waiting under the nonce, ready to be signed, if it's still there: for the signing page,
     * which shows it from the verifier's own copy.
     *
     * @phpstan-impure
     */
    public function prepared(string $nonce): ?PreparedIntent
    {
        $now = $this->clock->now();
        [$pending, $keys] = $this->transaction(static fn (VerifierState $state): array => [$state->pending[$nonce] ?? null, $state->keys]);

        return null === $pending || $now >= $pending->intent->expiresAt ? null : $this->ready($pending, $keys);
    }

    /**
     * The WebAuthn request options for an intent: its challenge, and the keys that may sign it.
     *
     * @param array<string, Key> $keys The verifier's keys
     */
    private function ready(PendingIntent $pending, array $keys): PreparedIntent
    {
        if ($pending->bootstrap) {
            $key = Key::fromParameters($pending->intent->parameters, $pending->intent->issuedAt);
            $keys = null === $key ? [] : [$key];
        }

        $options = PublicKeyCredentialRequestOptions::create(
            $pending->intent->challenge(),
            $this->config->relyingPartyId,
            // A list: the keys are kept by ID, and a map would be a JSON object, which browsers refuse.
            array_values(array_map(static fn (Key $key): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $key->credentialId), $keys)),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $this->config->intentLifetime * 1000,
        );

        return new PreparedIntent($pending->intent, $this->serializer->serialize($options, 'json'), $pending->bootstrap);
    }

    /**
     * Takes the intent waiting under the nonce, using the nonce up, if it's of the kind expected.
     *
     * @param \Closure(PendingIntent): bool $expected
     *
     * @throws IntentRefused
     */
    private function take(VerifierState $state, string $nonce, \Closure $expected): PendingIntent
    {
        // Not one the verifier made: recorded by a short hash, whatever its size or encoding (FUZZ-08).
        if (1 !== preg_match(self::NONCE, $nonce)) {
            $this->log->record('refused', ['nonce_hash' => substr(hash('sha256', $nonce), 0, 16), 'reason' => Refusal::UnknownIntent->value], $this->clock->now());

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
    private function check(VerifierState $state, PendingIntent $pending, array $keys, string $assertion): VerifiedIntent
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
     * Keeps a signed change to the keys until it's due, and records it.
     *
     * @param bool $now Whether it takes effect at once: only the first key, which no owner could cancel
     */
    private function requestChange(VerifierState $state, string $kind, Key $key, VerifiedIntent $verified, bool $now = false): KeyChange
    {
        $at = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $change = new KeyChange(Base64Url::encode(random_bytes(12)), $kind, $key, $at, $now ? $at : $at->modify(\sprintf('+%d seconds', $this->config->keyChangeDelay)), $verified->key->id());
        $state->keyChanges[$change->id] = $change;
        $this->record('key_change_requested', $verified, ['change' => $change->id, 'kind' => $kind, 'subject' => $key->id(), 'effective_at' => $change->effectiveAt->getTimestamp()]);

        return $change;
    }

    /**
     * Tells the notifier of a change just asked for, and applies it if it's due already.
     */
    private function requested(KeyChange $change, Intent $intent): KeyChange
    {
        $this->notifier->notify(new VerifierNotice(VerifierNotice::KEY_CHANGE_REQUESTED, $intent, $change->key, $change->requestedAt, $change));
        $this->applyKeyChanges();

        return $change;
    }

    /**
     * The key the authenticator registered, if its answer is a valid one to the registration: user
     * verified, at a signing origin, for the signing relying party.
     *
     * @throws IntentRefused
     */
    private function registered(PendingRegistration $pending, string $attestation): Key
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
        $steps->setAllowedOrigins($this->config->origins);
        $steps->setAlgorithmManager(Manager::create()->add(ES256::create(), Ed25519::create(), RS256::create()));

        return $steps;
    }

    /**
     * The key an "add key" intent describes, if it's a usable passkey public key the verifier hasn't got.
     *
     * @throws IntentRefused
     */
    private function newKey(VerifierState $state, Intent $intent): Key
    {
        $key = Key::fromParameters($intent->parameters, new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));
        // A key being removed is there still; one being added, not yet.
        $coming = array_map(static fn (KeyChange $change): string => $change->key->id(), $state->keyChanges);

        return null === $key || isset($state->keys[$key->id()]) || \in_array($key->id(), $coming, true) ? $this->refuse($intent, Refusal::InvalidKey) : $key;
    }

    private static function credentialRecord(Key $key): CredentialRecord
    {
        return CredentialRecord::create($key->credentialId, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), $key->publicKey, $key->userHandle, $key->signCount);
    }

    /**
     * @param array<string, int|string> $details
     */
    private function record(string $event, VerifiedIntent $verified, array $details = []): void
    {
        $this->log->record($event, [
            'action' => $verified->intent->action,
            'nonce' => $verified->intent->nonce,
            'key' => $verified->key->id(),
            'algorithm' => $verified->key->algorithm,
            ...$details,
        ], $verified->verifiedAt);
    }

    /**
     * @throws IntentRefused
     */
    private function refuse(Intent $intent, Refusal $refusal, ?\Throwable $previous = null): never
    {
        $this->log->record('refused', ['action' => $intent->action, 'nonce' => $intent->nonce, 'reason' => $refusal->value], $this->clock->now());

        throw new IntentRefused($refusal, $previous);
    }
}
