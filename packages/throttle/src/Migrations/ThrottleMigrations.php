<?php

declare(strict_types=1);

namespace YorvoID\Throttle\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The migrations of yorvoid/throttle, for the Migrator, after core's.
 *
 * @api
 */
final class ThrottleMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreateThrottle()];
    }
}
