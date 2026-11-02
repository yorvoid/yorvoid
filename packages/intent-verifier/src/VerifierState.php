<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Everything the verifier keeps: its keys, the intents waiting for a signature, the changes to its keys
 * waiting to take effect, and the hash of the bootstrap secret, if there is one. Changed only inside VerifierStore::transaction().
 *
 * @api
 */
final class VerifierState
{
    /** @var array<string, Key> By the key's ID */
    public array $keys = [];

    /** @var array<string, PendingIntent> By nonce */
    public array $pending = [];

    /** @var array<string, PendingRegistration> Passkey registrations waiting for the browser's answer, by ID */
    public array $registrations = [];

    /** @var array<string, KeyChange> Changes to the keys waiting to take effect, by their ID */
    public array $keyChanges = [];

    /** SHA-256 of the bootstrap secret, hex; null when there's none */
    public ?string $bootstrapSecretHash = null;
}
