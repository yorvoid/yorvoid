<?php

declare(strict_types=1);

namespace YorvoID\Relations\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The migrations of yorvoid/relations, for the Migrator, after core's.
 *
 * @api
 */
final class RelationsMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreateRelations()];
    }
}
