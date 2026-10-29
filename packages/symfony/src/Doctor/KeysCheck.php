<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Key\KeyRing;

/**
 * The signing keys: a key file that exists, only its owner can read, and holds a valid ring. Without
 * one there's nothing to check: nothing YorvoID does yet needs it, until signed checkpoints (AUD-003).
 *
 * @internal
 */
final readonly class KeysCheck implements Check
{
    public function __construct(
        private ?string $file,
    ) {
    }

    public function findings(): iterable
    {
        if (null === $this->file) {
            return;
        }

        if (!file_exists($this->file)) {
            yield Finding::problem(\sprintf('The key file "%s" doesn\'t exist.', $this->file), 'Create it with yorvoid:keys:rotate.', 'concepts/keys.md#rotating-keys');

            return;
        }

        // Throws what's wrong with it, which the doctor reports.
        KeyRing::fromFile($this->file);
    }
}
