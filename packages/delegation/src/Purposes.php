<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Exception\IntegratorError;

/**
 * The registered purposes, a tree (M3-1): a child delegation's purpose is its parent's or one below it.
 * Without any registered, purposes are flat, and compared for equality.
 *
 * @api
 *
 * @experimental
 */
final readonly class Purposes
{
    /**
     * @param array<string, string|null> $parents Each purpose, by name, with the one above it, if any
     *
     * @throws IntegratorError When one's parent isn't registered, or they loop
     */
    public function __construct(
        private array $parents = [],
    ) {
        foreach ($parents as $purpose => $parent) {
            $seen = [$purpose => true];

            for ($above = $parent; null !== $above; $above = $parents[$above] ?? null) {
                if (!\array_key_exists($above, $parents)) {
                    throw new IntegratorError(\sprintf('The purpose "%s" is under "%s", which isn\'t registered.', $purpose, $above), 'Register every purpose, the top ones with null above them.', 'concepts/delegation.md#purposes');
                }

                if (isset($seen[$above])) {
                    throw new IntegratorError(\sprintf('The purposes loop at "%s".', $above), 'Purposes form a tree: none is under itself.', 'concepts/delegation.md#purposes');
                }

                $seen[$above] = true;
            }
        }
    }

    /**
     * Whether a purpose is the other or below it; with none registered, whether they're equal.
     */
    public function within(?string $purpose, ?string $parent): bool
    {
        if (null === $parent) {
            return true;
        }

        for ($at = $purpose; null !== $at; $at = $this->parents[$at] ?? null) {
            if ($at === $parent) {
                return true;
            }
        }

        return false;
    }

    /**
     * A purpose and every purpose below it: what a request's may be.
     *
     * @return list<string>
     */
    public function below(string $purpose): array
    {
        $found = [$purpose];

        foreach (array_keys($this->parents) as $other) {
            if ($other !== $purpose && $this->within($other, $purpose)) {
                $found[] = $other;
            }
        }

        sort($found, \SORT_STRING);

        return $found;
    }
}
