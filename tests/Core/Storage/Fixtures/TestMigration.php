<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage\Fixtures;

use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * A migration made of a closure, for tests.
 */
final readonly class TestMigration implements Migration
{
    /**
     * @param \Closure(MigrationContext): void $up
     */
    public function __construct(
        private string $version,
        private \Closure $up,
        private LockProfile $lockProfile = LockProfile::Online,
        private string $description = 'A test migration',
    ) {
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function lockProfile(): LockProfile
    {
        return $this->lockProfile;
    }

    public function up(MigrationContext $context): void
    {
        ($this->up)($context);
    }
}
