<?php

declare(strict_types=1);

namespace YorvoID\Policy\Migrations;

use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * Drop the policy\'s own version table: the version is kept in core's versions table since 0.5 (D-44).
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
        return 'Drop the policy\'s own version table';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->execute(\sprintf('DROP TABLE %s', $context->table('policy_version')));
    }
}
