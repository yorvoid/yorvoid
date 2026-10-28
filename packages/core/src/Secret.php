<?php

declare(strict_types=1);

namespace YorvoID\Core;

use YorvoID\Core\Exception\IntegratorError;

/**
 * A secret value: a token's verifier, a recovery code, a key. It can't be printed, dumped, serialized or
 * leaked through a stack trace by accident, and it's only ever compared in constant time (PLT-053).
 *
 * `reveal()` returns the raw value for the few places that need it (hashing it, sending it once). Never
 * compare what it returns with `==` or `===`: YorvoID's PHPStan rules refuse that; use `equals()`.
 *
 * @api
 */
final readonly class Secret
{
    /** Below 128 bits, a random secret can be guessed or collide in practice */
    public const int MIN_RANDOM_BYTES = 16;

    /**
     * @param non-empty-string $value
     */
    private function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {
    }

    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        if ('' === $value) {
            throw new IntegratorError('A secret can\'t be empty.', 'Pass the secret\'s value, or create one with Secret::random().', 'concepts/secrets.md');
        }

        return new self($value);
    }

    /**
     * Random bytes from the operating system's cryptographically secure generator.
     */
    public static function random(int $bytes = 32): self
    {
        if ($bytes < self::MIN_RANDOM_BYTES) {
            throw new IntegratorError(\sprintf('A random secret of %d bytes is too short.', $bytes), \sprintf('Use at least %d bytes (128 bits).', self::MIN_RANDOM_BYTES), 'concepts/secrets.md');
        }

        return new self(random_bytes($bytes));
    }

    /**
     * Whether the other secret is the same, in time that doesn't depend on where they differ.
     */
    public function equals(#[\SensitiveParameter] self|string $other): bool
    {
        return hash_equals($this->value, $other instanceof self ? $other->value : $other);
    }

    /**
     * @return non-empty-string
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Shown by var_dump(), print_r() and debuggers instead of the value.
     *
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A secret can\'t be serialized: store it hashed or encrypted instead.');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('A secret can\'t be unserialized.');
    }
}
