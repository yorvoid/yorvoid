<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

use YorvoID\IntentVerifier\CanonicalJson;
use YorvoID\IntentVerifier\VerifierLog;

/**
 * The verifier's log as a file of JSON lines, appended to and hash-chained: each entry holds the
 * SHA-256 of the one before, so a changed, removed or reordered entry shows (isIntact()). Appending
 * reads only the last line, and checking reads a line at a time, however long the log. rotate() moves
 * the entries to an archive and starts the file again, linked to the archive's last entry.
 *
 * @api
 */
final readonly class FileVerifierLog implements VerifierLog
{
    /** The hash before the first entry */
    private const string START = '0000000000000000000000000000000000000000000000000000000000000000';

    /** How much of the end of the file is read at a time, for the last line */
    private const int BLOCK = 4096;

    /** The first entry of a file that rotate() started again */
    public const string ROTATED = 'log_rotated';

    public function __construct(
        private string $path,
    ) {
    }

    public function record(string $event, array $details, \DateTimeImmutable $at): void
    {
        LockedFile::run($this->path, 'The verifier\'s log', function ($file) use ($event, $details, $at): void {
            $this->append($file, $event, $details, $at, self::lastHash($file));
        });
    }

    /**
     * Moves every entry to the archive, which must not exist, and starts the log again with an entry
     * that names the archive and holds its last entry's hash: the chain goes on. Keep the archives: each
     * is checked on its own (isIntact()), and the link between them by that first entry.
     */
    public function rotate(string $archive, \DateTimeImmutable $at): void
    {
        LockedFile::run($this->path, 'The verifier\'s log', function ($file) use ($archive, $at): void {
            $last = self::lastHash($file);
            $copy = @fopen($archive, 'x');

            if (false === $copy) {
                throw new StorageError(\sprintf('The archive %s exists already, or can\'t be created.', $archive));
            }

            // The copy is closed, and so written, when this returns or throws.
            LockedFile::expect(chmod($archive, 0o600), 'The verifier\'s log archive', $archive);
            LockedFile::expect(rewind($file), 'The verifier\'s log', $this->path);
            LockedFile::expect(stream_copy_to_stream($file, $copy), 'The verifier\'s log archive', $archive);
            LockedFile::expect(ftruncate($file, 0), 'The verifier\'s log', $this->path);
            $this->append($file, self::ROTATED, ['archive' => basename($archive)], $at, $last);
        });
    }

    /**
     * Whether every entry is as it was written: each one's hash matches, and holds the one before. A file
     * that rotate() started again begins with the archive's last hash.
     */
    public function isIntact(): bool
    {
        $file = is_file($this->path) ? @fopen($this->path, 'r') : null;

        if (false === $file) {
            return false;
        }

        $previous = self::START;
        $first = true;

        // The file is closed when this returns.
        while (null !== $file && false !== ($line = fgets($file))) {
            $line = rtrim($line, "\n");
            $entry = json_decode($line, true);

            if (!\is_array($entry) || !\is_string($entry['hash'] ?? null) || !\is_string($entry['previous'] ?? null) || !($entry['previous'] === $previous || ($first && self::ROTATED === ($entry['event'] ?? null)))) {
                return false;
            }

            $hash = $entry['hash'];
            unset($entry['hash']);
            $entry['details'] = (object) $entry['details'];

            if (!hash_equals(hash('sha256', CanonicalJson::encode($entry)), $hash) || CanonicalJson::encode([...$entry, 'hash' => $hash]) !== $line) {
                return false;
            }

            $previous = $hash;
            $first = false;
        }

        return true;
    }

    /**
     * @param resource             $file
     * @param array<string, mixed> $details
     */
    private function append($file, string $event, array $details, \DateTimeImmutable $at, string $previous): void
    {
        $entry = ['at' => $at->getTimestamp(), 'details' => (object) $details, 'event' => $event, 'previous' => $previous];
        LockedFile::expect(0 === fseek($file, 0, \SEEK_END), 'The verifier\'s log', $this->path);
        LockedFile::expect(fwrite($file, CanonicalJson::encode([...$entry, 'hash' => hash('sha256', CanonicalJson::encode($entry))])."\n"), 'The verifier\'s log', $this->path);
    }

    /**
     * The last entry's hash, read from the end of the file: only the last line.
     *
     * @param resource $file
     */
    private static function lastHash($file): string
    {
        $stat = fstat($file);
        \assert(false !== $stat);
        $size = $stat['size'];

        if (0 === $size) {
            return self::START;
        }

        // Back from the end, a block at a time, until the last line is whole.
        $tail = '';
        $position = $size;

        do {
            $read = min(self::BLOCK, $position);
            \assert($read > 0);
            $position -= $read;
            fseek($file, $position);
            $tail = (string) fread($file, $read).$tail;
            $lines = explode("\n", rtrim($tail, "\n"));
        } while (1 === \count($lines) && $position > 0);

        $last = json_decode(end($lines), true);

        if (!\is_array($last) || !\is_string($last['hash'] ?? null)) {
            throw new StorageError('The verifier\'s log isn\'t what the verifier wrote.');
        }

        return $last['hash'];
    }
}
