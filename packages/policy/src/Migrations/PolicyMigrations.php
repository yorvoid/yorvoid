<?php

declare(strict_types=1);

namespace YorvoID\Policy\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The migrations of yorvoid/policy, for the Migrator, after core's.
 *
 * @api
 */
final class PolicyMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreatePolicy(), new CreateGroups(), new CreateAttributes()];
    }
}
