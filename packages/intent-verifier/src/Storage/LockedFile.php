<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

/**
 * A file opened and locked exclusively for the time of some work, so processes take turns with it.
 *
 * @internal
 */
final class LockedFile
{
    /**
     * @template T
     *
     * @param string                $what E.g. "The verifier's state", for messages
     * @param \Closure(resource): T $work
     *
     * @return T
     */
    public static function run(string $path, string $what, \Closure $work): mixed
    {
        $new = !file_exists($path);
        $file = @fopen($path, 'c+');

        if (false === $file) {
            throw new StorageError(\sprintf('%s %s can\'t be opened.', $what, $path));
        }

        try {
            if ($new) {
                self::expect(chmod($path, 0o600), $what, $path);
            }

            self::expect(flock($file, \LOCK_EX), $what, $path);

            return $work($file);
        } finally {
            // Closing releases the lock.
            fclose($file);
        }
    }

    /**
     * Throws unless a file operation succeeded.
     */
    public static function expect(mixed $result, string $what, string $path): void
    {
        if (false === $result) {
            throw new StorageError(\sprintf('%s %s can\'t be written.', $what, $path));
        }
    }
}
