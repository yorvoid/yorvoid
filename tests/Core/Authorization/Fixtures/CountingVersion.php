<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\DecisionScope;
use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Realm;

/**
 * A version source whose version a test sets, and that reads from the scope as the cached stores do.
 */
final class CountingVersion implements VersionSource
{
    public bool $fails = false;

    public function __construct(
        private readonly string $name,
        public int $version,
        private readonly ?DecisionScope $scope = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(Realm $realm): int
    {
        if ($this->fails) {
            throw new \RuntimeException('Unreachable.');
        }

        return $this->scope?->version($realm, $this->name) ?? $this->version;
    }
}
