<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The migrations of yorvoid/core, for the Migrator.
 *
 * @api
 */
final class CoreMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreatePrincipals(), new CreateSecurityLog()];
    }
}
