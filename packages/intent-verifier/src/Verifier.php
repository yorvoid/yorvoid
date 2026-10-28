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
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
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
 * Its keys change only by intents too: a key is added with a signature by an existing key, removed
 * with a signature, never the last one. The first key comes with the bootstrap secret, signed by
 * itself; a new secret is the host's way back in when every key is lost (INT-005, 006, 016).
 *
 * It trusts nothing the console says: it needs no framework, no database, and none of YorvoID's other
 * packages (INT-007).
 *
 * @api
 */
final readonly class Verifier
{
    private SerializerInterface $serializer;

    public function __construct(
        private VerifierConfig $config,
        private OperationPolicy $policy,
        private VerifierStore $store,
        private VerifierLog $log,
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
     */
    public function prepare(string $action, array $parameters): PreparedIntent
    {
        $operation = $this->policy->get($action);

        if (!$operation->signed) {
            throw new ConfigurationError(\sprintf('The operation %s runs unsigned: there\'s nothing to prepare.', $action));
        }

        return $this->begin($operation, $parameters, false);
    }

    /**
     * Prepares the first key's addition: an "add key" intent that the key itself signs, with the
     * bootstrap secret (see bootstrap()).
     *
     * @param array<string, mixed> $key As OperationPolicy::ADD_KEY's parameters
     */
    public function prepareBootstrap(array $key): PreparedIntent
    {
        return $this->begin($this->policy->get(OperationPolicy::ADD_KEY), $key, true);
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
        return $this->attempt(function (VerifierState $state) use ($nonce, $assertion): VerifiedIntent {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => !$pending->bootstrap && !str_starts_with($pending->intent->action, 'yorvoid.verifier.'));
            $verified = $this->check($state, $pending, $state->keys, $assertion);
            $this->record('verified', $verified);

            return $verified;
        });
    }

    /**
     * Adds the key a prepared "add key" intent describes, signed by one of the verifier's keys.
     *
     * @throws IntentRefused
     */
    public function addKey(string $nonce, string $assertion): Key
    {
        return $this->attempt(function (VerifierState $state) use ($nonce, $assertion): Key {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => !$pending->bootstrap && OperationPolicy::ADD_KEY === $pending->intent->action);
            $key = $this->newKey($state, $pending->intent);
            $verified = $this->check($state, $pending, $state->keys, $assertion);
            $state->keys[$key->id()] = $key;
            $this->record('key_added', $verified, ['added' => $key->id(), 'algorithm' => $key->algorithm]);

            return $key;
        });
    }

    /**
     * Removes the key a prepared "remove key" intent names, signed by one of the verifier's keys; never
     * the last one.
     *
     * @return Key The key removed
     *
     * @throws IntentRefused
     */
    public function removeKey(string $nonce, string $assertion): Key
    {
        return $this->attempt(function (VerifierState $state) use ($nonce, $assertion): Key {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => OperationPolicy::REMOVE_KEY === $pending->intent->action);
            $verified = $this->check($state, $pending, $state->keys, $assertion);
            $removed = $pending->intent->parameters['credential_id'];
            $key = \is_string($removed) && isset($state->keys[$removed]) ? $state->keys[$removed] : $this->refuse($pending->intent, Refusal::UnknownKey);

            if (1 === \count($state->keys)) {
                $this->refuse($pending->intent, Refusal::LastKey);
            }

            unset($state->keys[$key->id()]);
            $this->record('key_removed', $verified, ['removed' => $key->id()]);

            return $key;
        });
    }

    /**
     * Creates a bootstrap secret, replacing any earlier one: for the host's root (e.g. a console
     * command run by root), never for a web page. With it, the first key is added; when keys exist, it's
     * the way back in after every one was lost (INT-016). Recorded; tell every owner it happened.
     *
     * @return string The secret, shown only now
     */
    public function createBootstrapSecret(): string
    {
        $secret = Base64Url::encode(random_bytes(32));

        $this->store->transaction(function (VerifierState $state) use ($secret): void {
            $state->bootstrapSecretHash = hash('sha256', $secret);
            $this->log->record('bootstrap_secret_created', ['keys' => \count($state->keys)], $this->clock->now());
        });

        return $secret;
    }

    /**
     * Adds a key with the bootstrap secret: the key signed its own "add key" intent, prepared with
     * prepareBootstrap(). The secret works once.
     *
     * @throws IntentRefused
     */
    public function bootstrap(#[\SensitiveParameter] string $secret, string $nonce, string $assertion): Key
    {
        return $this->attempt(function (VerifierState $state) use ($secret, $nonce, $assertion): Key {
            $pending = $this->take($state, $nonce, static fn (PendingIntent $pending): bool => $pending->bootstrap);

            if (null === $state->bootstrapSecretHash || !hash_equals($state->bootstrapSecretHash, hash('sha256', $secret))) {
                $this->refuse($pending->intent, Refusal::InvalidBootstrapSecret);
            }

            $key = $this->newKey($state, $pending->intent);
            $verified = $this->check($state, $pending, [$key->id() => $key], $assertion);
            $state->keys[$key->id()] = $verified->key;
            $state->bootstrapSecretHash = null;
            $this->record('bootstrapped', $verified, ['added' => $key->id(), 'algorithm' => $key->algorithm]);

            return $verified->key;
        });
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
        return $this->store->transaction(static fn (VerifierState $state): array => array_values($state->keys));
    }

    /**
     * Forgets intents that expired unsigned; run it now and then, e.g. hourly.
     *
     * @return int How many
     */
    public function purge(): int
    {
        $now = $this->clock->now();

        return $this->store->transaction(static function (VerifierState $state) use ($now): int {
            $count = \count($state->pending);
            $state->pending = array_filter($state->pending, static fn (PendingIntent $pending): bool => $now < $pending->intent->expiresAt);

            return $count - \count($state->pending);
        });
    }

    /**
     * Runs the work in a store transaction that's saved even when the work refuses: a refused attempt
     * still uses its nonce up. Then throws the refusal, if any.
     *
     * @template T of object
     *
     * @param \Closure(VerifierState): T $work
     *
     * @return T
     *
     * @throws IntentRefused
     */
    private function attempt(\Closure $work): object
    {
        $result = $this->store->transaction(static function (VerifierState $state) use ($work): object {
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

        $keys = $this->store->transaction(function (VerifierState $state) use ($pending): array {
            $state->pending[$pending->intent->nonce] = $pending;
            $this->log->record('prepared', ['action' => $pending->intent->action, 'nonce' => $pending->intent->nonce, 'bootstrap' => $pending->bootstrap], $this->clock->now());

            return $pending->bootstrap ? [] : $state->keys;
        });

        if ($bootstrap) {
            $key = Key::fromParameters($parameters, $issuedAt);
            $keys = null === $key ? [] : [$key];
        }

        $options = PublicKeyCredentialRequestOptions::create(
            $intent->challenge(),
            $this->config->relyingPartyId,
            // A list: the keys are kept by ID, and a map would be a JSON object, which browsers refuse.
            array_values(array_map(static fn (Key $key): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $key->credentialId), $keys)),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $this->config->intentLifetime * 1000,
        );

        return new PreparedIntent($intent, $this->serializer->serialize($options, 'json'));
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
        $pending = $state->pending[$nonce] ?? null;
        unset($state->pending[$nonce]);

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
            $credential = $this->parse($assertion);
        } catch (\Throwable $exception) {
            $this->refuse($pending->intent, Refusal::InvalidSignature, $exception);
        }

        $response = $credential instanceof PublicKeyCredential ? $credential->response : null;

        if (!$response instanceof AuthenticatorAssertionResponse) {
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

        $options = PublicKeyCredentialRequestOptions::create($pending->intent->challenge(), $this->config->relyingPartyId, [], PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED);
        $steps = new CeremonyStepManagerFactory();
        $steps->setAllowedOrigins($this->config->origins);
        $steps->setAlgorithmManager(Manager::create()->add(ES256::create(), Ed25519::create(), RS256::create()));

        try {
            $record = AuthenticatorAssertionResponseValidator::create($steps->requestCeremony())->check(self::credentialRecord($key), $response, $options, $this->config->relyingPartyId, null);
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
     * The key an "add key" intent describes, if it's a usable passkey public key the verifier hasn't got.
     *
     * @throws IntentRefused
     */
    private function newKey(VerifierState $state, Intent $intent): Key
    {
        $key = Key::fromParameters($intent->parameters, new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));

        return null === $key || isset($state->keys[$key->id()]) ? $this->refuse($intent, Refusal::InvalidKey) : $key;
    }

    /**
     * The browser's answer as the library reads it, which isn't always a PublicKeyCredential: "{}" is an
     * array.
     */
    private function parse(string $assertion): mixed
    {
        return $this->serializer->deserialize($assertion, PublicKeyCredential::class, 'json');
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
