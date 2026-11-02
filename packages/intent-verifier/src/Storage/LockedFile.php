<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

/**
 * A file opened and locked exclusively for the time of some work, so processes take turns with it. It
 * must be the process's own: a regular file, not a symbolic link, owned by the process's user, and
 * neither readable nor writable by anyone else; one that isn't is refused, rather than trusted.
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
        if (is_link($path)) {
            throw new StorageError(\sprintf('%s %s is a symbolic link: give the file itself.', $what, $path));
        }

        $new = !file_exists($path);
        $file = @fopen($path, 'c+');

        if (false === $file) {
            throw new StorageError(\sprintf('%s %s can\'t be opened.', $what, $path));
        }

        try {
            if ($new) {
                self::expect(chmod($path, 0o600), $what, $path);
            }

            self::requireOwn($file, $path, $what);

            self::expect(flock($file, \LOCK_EX), $what, $path);

            return $work($file);
        } finally {
            // Closing releases the lock.
            fclose($file);
        }
    }

    /**
     * Refuses what was opened unless it's the file at the path (not one a link pointed to in the
     * meantime), regular, the process user's, and private to it.
     *
     * @param resource $file
     */
    private static function requireOwn($file, string $path, string $what): void
    {
        clearstatcache(true, $path);
        $opened = fstat($file);
        $named = lstat($path);

        if (false === $opened || false === $named || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev'] || 0o100000 !== ($opened['mode'] & 0o170000)) {
            throw new StorageError(\sprintf('%s %s isn\'t a regular file at that path.', $what, $path));
        }

        if (\function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid()) {
            throw new StorageError(\sprintf('%s %s belongs to another user: it must be the verifier\'s own.', $what, $path));
        }

        if (0 !== ($opened['mode'] & 0o077)) {
            throw new StorageError(\sprintf('%s %s can be read or written by other users: chmod 600 it.', $what, $path));
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
