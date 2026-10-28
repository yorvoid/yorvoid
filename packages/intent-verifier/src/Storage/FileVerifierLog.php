<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

use YorvoID\IntentVerifier\CanonicalJson;
use YorvoID\IntentVerifier\VerifierLog;

/**
 * The verifier's log as a file of JSON lines, appended to and hash-chained: each entry holds the
 * SHA-256 of the one before, so a changed, removed or reordered entry shows (isIntact()).
 *
 * @api
 */
final readonly class FileVerifierLog implements VerifierLog
{
    /** The hash before the first entry */
    private const string START = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private string $path,
    ) {
    }

    public function record(string $event, array $details, \DateTimeImmutable $at): void
    {
        LockedFile::run($this->path, 'The verifier\'s log', function ($file) use ($event, $details, $at): void {
            $entry = ['at' => $at->getTimestamp(), 'details' => (object) $details, 'event' => $event, 'previous' => self::lastHash($file)];
            LockedFile::expect(fwrite($file, CanonicalJson::encode([...$entry, 'hash' => hash('sha256', CanonicalJson::encode($entry))])."\n"), 'The verifier\'s log', $this->path);
        });
    }

    /**
     * Whether every entry is as it was written: each one's hash matches, and holds the one before.
     */
    public function isIntact(): bool
    {
        $lines = is_file($this->path) ? file($this->path, \FILE_IGNORE_NEW_LINES) : [];
        $previous = self::START;

        foreach (false === $lines ? [] : $lines as $line) {
            $entry = json_decode($line, true);

            if (!\is_array($entry) || !\is_string($entry['hash'] ?? null) || ($entry['previous'] ?? null) !== $previous) {
                return false;
            }

            $hash = $entry['hash'];
            unset($entry['hash']);
            $entry['details'] = (object) $entry['details'];

            if (!hash_equals(hash('sha256', CanonicalJson::encode($entry)), $hash) || CanonicalJson::encode([...$entry, 'hash' => $hash]) !== $line) {
                return false;
            }

            $previous = $hash;
        }

        return true;
    }

    /**
     * @param resource $file
     */
    private static function lastHash($file): string
    {
        $contents = stream_get_contents($file);
        $lines = false === $contents ? [] : array_filter(explode("\n", $contents), static fn (string $line): bool => '' !== $line);
        if ([] === $lines) {
            return self::START;
        }

        $last = json_decode(end($lines), true);

        if (!\is_array($last) || !\is_string($last['hash'] ?? null)) {
            throw new StorageError('The verifier\'s log isn\'t what the verifier wrote.');
        }

        return $last['hash'];
    }
}
