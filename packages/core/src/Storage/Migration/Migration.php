<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Migration;

/**
 * One step of YorvoID's schema. Migrations only go forward: a mistake is fixed by the next migration,
 * as rolling back a schema under a running application loses data or breaks the previous version.
 *
 * @api
 */
interface Migration
{
    /**
     * The time it was written and what it does, e.g. "20261027180000_core_security_log". Migrations run
     * in the order of their versions, across all packages.
     */
    public function version(): string;

    public function description(): string;

    public function lockProfile(): LockProfile;

    public function up(MigrationContext $context): void;
}
