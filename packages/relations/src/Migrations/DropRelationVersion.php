<?php

declare(strict_types=1);

namespace YorvoID\Relations\Migrations;

use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * Drop the relations\' own version table: the version is kept in core's versions table since 0.5 (D-44).
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
        return 'Drop the relations\' own version table';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->execute(\sprintf('DROP TABLE %s', $context->table('relation_version')));
    }
}
