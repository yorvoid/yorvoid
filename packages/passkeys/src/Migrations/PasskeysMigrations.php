<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The migrations of yorvoid/passkeys, for the Migrator, after core's and authn's.
 *
 * @api
 */
final class PasskeysMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreatePasskeys()];
    }
}
