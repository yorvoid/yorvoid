<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

use YorvoID\IntentVerifier\Base64Url;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\Key;
use YorvoID\IntentVerifier\PendingIntent;
use YorvoID\IntentVerifier\VerifierState;
use YorvoID\IntentVerifier\VerifierStore;

/**
 * The verifier's state in one JSON file, readable only by its owner: the keys, the intents waiting for
 * a signature (so a nonce works once, also across restarts: INT-009), and the bootstrap secret's hash.
 * Each transaction holds an exclusive lock on the file, so processes on the same host take turns.
 *
 * Keep it where only the verifier's user can write, e.g. /var/lib/acme-verifier/state.json.
 *
 * @api
 */
final readonly class FileVerifierStore implements VerifierStore
{
    private const int VERSION = 1;

    public function __construct(
        private string $path,
    ) {
    }

    public function transaction(\Closure $work): mixed
    {
        return LockedFile::run($this->path, 'The verifier\'s state', function ($file) use ($work): mixed {
            $contents = stream_get_contents($file);
            $state = '' === $contents ? new VerifierState() : self::state((string) $contents);
            $result = $work($state);
            $encoded = json_encode(self::data($state), \JSON_THROW_ON_ERROR);
            LockedFile::expect(rewind($file), 'The verifier\'s state', $this->path);
            LockedFile::expect(fwrite($file, $encoded), 'The verifier\'s state', $this->path);
            LockedFile::expect(ftruncate($file, \strlen($encoded)), 'The verifier\'s state', $this->path);

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(VerifierState $state): array
    {
        return [
            'version' => self::VERSION,
            'keys' => array_map(static fn (Key $key): array => [
                'credential_id' => Base64Url::encode($key->credentialId),
                'public_key' => Base64Url::encode($key->publicKey),
                'user_handle' => Base64Url::encode($key->userHandle),
                'algorithm' => $key->algorithm,
                'label' => $key->label,
                'added_at' => $key->addedAt->getTimestamp(),
                'sign_count' => $key->signCount,
            ], $state->keys),
            'pending' => array_map(static fn (PendingIntent $pending): array => [
                'action' => $pending->intent->action,
                'parameters' => $pending->intent->parameters,
                'audience' => $pending->intent->audience,
                'nonce' => $pending->intent->nonce,
                'issued_at' => $pending->intent->issuedAt->getTimestamp(),
                'expires_at' => $pending->intent->expiresAt->getTimestamp(),
                'bootstrap' => $pending->bootstrap,
            ], $state->pending),
            'bootstrap_secret_hash' => $state->bootstrapSecretHash,
        ];
    }

    /**
     * The state as the file holds it. A wrong type anywhere fails a typed constructor or helper, and so
     * the whole read; the structure itself is checked first, since iterating over a non-list would skip
     * silently.
     */
    private static function state(string $contents): VerifierState
    {
        try {
            $data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

            if (!\is_array($data) || self::VERSION !== ($data['version'] ?? null) || !\is_array($data['keys'] ?? null) || !\is_array($data['pending'] ?? null)) {
                throw new \UnexpectedValueException('Not a state.');
            }

            $state = new VerifierState();

            foreach ($data['keys'] as $key) {
                \assert(\is_array($key));
                $key = new Key(self::bytes($key['credential_id']), self::bytes($key['public_key']), self::bytes($key['user_handle']), self::int($key['algorithm']), self::string($key['label']), self::time($key['added_at']), self::int($key['sign_count']));
                $state->keys[$key->id()] = $key;
            }

            foreach ($data['pending'] as $pending) {
                \assert(\is_array($pending) && \is_array($pending['parameters']) && \is_bool($pending['bootstrap']));
                /** @var array<string, mixed> $parameters */
                $parameters = $pending['parameters'];
                $intent = new Intent(self::string($pending['action']), $parameters, self::string($pending['audience']), self::string($pending['nonce']), self::time($pending['issued_at']), self::time($pending['expires_at']));
                $state->pending[$intent->nonce] = new PendingIntent($intent, $pending['bootstrap']);
            }

            $state->bootstrapSecretHash = self::nullableString($data['bootstrap_secret_hash']);

            return $state;
        } catch (\Throwable $exception) {
            throw new StorageError('The verifier\'s state isn\'t what the verifier wrote.', previous: $exception);
        }
    }

    private static function bytes(mixed $value): string
    {
        $bytes = Base64Url::decode(self::string($value));
        \assert(null !== $bytes);

        return $bytes;
    }

    private static function string(mixed $value): string
    {
        \assert(\is_string($value));

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        \assert(null === $value || \is_string($value));

        return $value;
    }

    private static function int(mixed $value): int
    {
        \assert(\is_int($value));

        return $value;
    }

    private static function time(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.self::int($value));
    }
}
