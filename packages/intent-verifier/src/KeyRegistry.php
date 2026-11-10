<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use Psr\Clock\ClockInterface;

/**
 * The verifier's keys and their changes (A-7), as spec/verifier/key_registry.qnt describes them: the
 * first key with the bootstrap secret, at once; every later change signed by one of the keys, delayed,
 * cancellable, and never leaving the verifier without a key, counting the keys already leaving. It
 * works on the state inside the verifier's transaction; the signatures are checked before (IntentChecks).
 *
 * @internal Verifier's; its API is Verifier's
 */
final readonly class KeyRegistry
{
    public function __construct(
        private VerifierConfig $config,
        private VerifierLog $log,
        private IntentChecks $checks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The key an "add key" intent describes, if it's a usable passkey public key the verifier hasn't
     * got, nor is getting.
     *
     * @throws IntentRefused
     */
    public function newKey(VerifierState $state, Intent $intent): Key
    {
        $key = Key::fromParameters($intent->parameters, new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));
        // A key being removed is there still; one being added, not yet.
        $coming = array_map(static fn (KeyChange $change): string => $change->key->id(), $state->keyChanges);

        return null === $key || isset($state->keys[$key->id()]) || \in_array($key->id(), $coming, true) ? $this->checks->refuse($intent, Refusal::InvalidKey) : $key;
    }

    /**
     * Asks to add the key, signed by one of the verifier's keys: it waits the delay.
     */
    public function add(VerifierState $state, Key $key, VerifiedIntent $verified): KeyChange
    {
        return $this->request($state, KeyChange::ADD, $key, $verified);
    }

    /**
     * Asks to remove the key the intent names, signed by one of the verifier's keys: it waits the delay,
     * and never leaves the verifier without a key, counting those already leaving.
     *
     * @throws IntentRefused
     */
    public function remove(VerifierState $state, VerifiedIntent $verified): KeyChange
    {
        $removed = $verified->intent->parameters['credential_id'] ?? null;
        $leaving = array_map(static fn (KeyChange $change): string => $change->key->id(), array_filter($state->keyChanges, static fn (KeyChange $change): bool => KeyChange::REMOVE === $change->kind));
        $key = \is_string($removed) && isset($state->keys[$removed]) && !\in_array($removed, $leaving, true) ? $state->keys[$removed] : $this->checks->refuse($verified->intent, Refusal::UnknownKey);

        if (\count($state->keys) - \count($leaving) <= 1) {
            $this->checks->refuse($verified->intent, Refusal::LastKey);
        }

        return $this->request($state, KeyChange::REMOVE, $key, $verified);
    }

    /**
     * Cancels the change the intent names, before it takes effect.
     *
     * @throws IntentRefused
     */
    public function cancel(VerifierState $state, VerifiedIntent $verified): KeyChange
    {
        $id = $verified->intent->parameters['change'] ?? null;
        $change = \is_string($id) && isset($state->keyChanges[$id]) ? $state->keyChanges[$id] : $this->checks->refuse($verified->intent, Refusal::UnknownKeyChange);
        unset($state->keyChanges[$change->id]);
        $this->record('key_change_cancelled', $verified, ['change' => $change->id]);

        return $change;
    }

    /**
     * Sets the bootstrap secret, replacing any earlier one.
     */
    public function createSecret(VerifierState $state, string $secret, \DateTimeImmutable $expiresAt): void
    {
        $state->bootstrapSecretHash = Digest::sha256($secret);
        $state->bootstrapSecretExpiresAt = $expiresAt;
        $this->log->record('bootstrap_secret_created', ['keys' => \count($state->keys), 'expires_at' => $expiresAt->getTimestamp()], $this->clock->now());
    }

    /**
     * Whether the secret is the bootstrap secret, and it still works.
     */
    public function secretWorks(VerifierState $state, #[\SensitiveParameter] string $secret): bool
    {
        // A secret and its expiry are set and cleared together.
        return null !== $state->bootstrapSecretExpiresAt && $this->clock->now() < $state->bootstrapSecretExpiresAt && hash_equals((string) $state->bootstrapSecretHash, hash('sha256', $secret));
    }

    /**
     * Adds the key that signed its own addition with the bootstrap secret, which then works no more:
     * the first key at once, any later one after the delay.
     */
    public function bootstrap(VerifierState $state, VerifiedIntent $verified): KeyChange
    {
        $state->bootstrapSecretHash = null;
        $state->bootstrapSecretExpiresAt = null;

        return $this->request($state, KeyChange::ADD, $verified->key, $verified, [] === $state->keys);
    }

    /**
     * Applies the changes that are due.
     *
     * @return list<KeyChange> The changes that took effect
     */
    public function applyDue(VerifierState $state, \DateTimeImmutable $now): array
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
     * Keeps a signed change to the keys until it's due, and records it.
     *
     * @param bool $now Whether it takes effect at once: only the first key, which no owner could cancel
     */
    private function request(VerifierState $state, string $kind, Key $key, VerifiedIntent $verified, bool $now = false): KeyChange
    {
        $at = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $change = new KeyChange(Base64Url::encode(random_bytes(12)), $kind, $key, $at, $now ? $at : $at->modify(\sprintf('+%d seconds', $this->config->keyChangeDelay)), $verified->key->id());
        $state->keyChanges[$change->id] = $change;
        $this->record('key_change_requested', $verified, ['change' => $change->id, 'kind' => $kind, 'subject' => $key->id(), 'effective_at' => $change->effectiveAt->getTimestamp()]);

        return $change;
    }

    /**
     * @param array<string, int|string> $details
     */
    private function record(string $event, VerifiedIntent $verified, array $details): void
    {
        $this->log->record($event, [
            'action' => $verified->intent->action,
            'nonce' => $verified->intent->nonce,
            'key' => $verified->key->id(),
            'algorithm' => $verified->key->algorithm,
            ...$details,
        ], $verified->verifiedAt);
    }
}
