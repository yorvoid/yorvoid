<?php

declare(strict_types=1);

namespace YorvoID\Relations\Migrations;

use YorvoID\Core\Authorization\Storage\DbalVersionStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * Moves the relations' version of each realm into core's versions table, then drops its own table: the
 * version is kept there since 0.5 (D-44), and a realm's version never goes back.
 *
 * @internal
 */
final class DropRelationVersion implements Migration
{
    public function version(): string
    {
        return '20261123180100_relation_version_dropped';
    }

    public function description(): string
    {
        return 'Move the relations\' versions to core\'s table, and drop its own';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        // Each realm keeps its version: a cache keyed on versions would otherwise serve what it kept for a
        // version the realm reaches again (D-44). Core's table is made first (its migration is older).
        $context->execute(\sprintf('INSERT INTO %s (realm, source, version) SELECT realm, \'relations\', version FROM %s', $context->table(DbalVersionStore::TABLE), $context->table('relation_version')));
        $context->execute(\sprintf('DROP TABLE %s', $context->table('relation_version')));
    }
}
