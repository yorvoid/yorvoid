<?php

declare(strict_types=1);

namespace YorvoID\Authn\Migrations;

use YorvoID\Core\Storage\Migration\Migration;

/**
 * The migrations of yorvoid/authn, for the Migrator, after core's.
 *
 * @api
 */
final class AuthnMigrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [new CreateSessions(), new CreateOneTimeTokens(), new CreateSetup(), new CreateCredentials(), new CreateRecovery(), new CreateKnownDevices()];
    }
}
