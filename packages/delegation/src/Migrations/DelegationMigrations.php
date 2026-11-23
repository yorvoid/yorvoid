<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The delegation package's migrations.
 *
 * @api
 */
final class DelegationMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreateDelegations()];
    }
}
