<?php

declare(strict_types=1);

namespace YorvoID\Policy\Migrations;

use YorvoID\Core\Authorization\Storage\DbalVersionStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * Moves the policy's version of each realm into core's versions table, then drops its own table: the
 * version is kept there since 0.5 (D-44), and a realm's version never goes back.
 *
 * @internal
 */
final class DropPolicyVersion implements Migration
{
    public function version(): string
    {
        return '20261123180100_policy_version_dropped';
    }

    public function description(): string
    {
        return 'Move the policy\'s versions to core\'s table, and drop its own';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        // Each realm keeps its version: a cache keyed on versions would otherwise serve what it kept for a
        // version the realm reaches again (D-44). Core's table is made first (its migration is older).
        $context->execute(\sprintf('INSERT INTO %s (realm, source, version) SELECT realm, \'policy\', version FROM %s', $context->table(DbalVersionStore::TABLE), $context->table('policy_version')));
        $context->execute(\sprintf('DROP TABLE %s', $context->table('policy_version')));
    }
}
