<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Everything the verifier keeps: its keys, the intents waiting for a signature, and the hash of the
 * bootstrap secret, if there is one. Changed only inside VerifierStore::transaction().
 *
 * @api
 */
final class VerifierState
{
    /** @var array<string, Key> By the key's ID */
    public array $keys = [];

    /** @var array<string, PendingIntent> By nonce */
    public array $pending = [];

    /** SHA-256 of the bootstrap secret, hex; null when there's none */
    public ?string $bootstrapSecretHash = null;
}
