<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use YorvoID\Authn\Migrations\AuthnMigrations;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Passkeys\Migrations\PasskeysMigrations;
use YorvoID\Throttle\Migrations\ThrottleMigrations;

/**
 * The migrations of every package the bundle uses, in order: what yorvoid:migrate runs and
 * yorvoid:doctor expects to have run.
 *
 * @internal
 */
final class Migrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return array_merge(CoreMigrations::all(), AuthnMigrations::all(), PasskeysMigrations::all(), ThrottleMigrations::all());
    }
}
