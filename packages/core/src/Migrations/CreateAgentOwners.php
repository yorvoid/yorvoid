<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * An agent's owner and profile (AGT-001): nullable columns on principals, and an index to find an
 * owner's agents. No foreign key: principals are never removed (a deletion keeps a tombstone), so one
 * would refuse nothing. Refusing to delete an owner of agents is the decision's, and the handler's
 * under the realm's lock.
 *
 * @internal
 */
final class CreateAgentOwners implements Migration
{
    public function version(): string
    {
        return '20261123180200_core_agent_owners';
    }

    public function description(): string
    {
        return 'Add an agent\'s owner and profile to principals';
    }

    public function lockProfile(): LockProfile
    {
        // Nullable columns, added without rewriting the table; the index is built on a column of nulls.
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $table = $context->table(DbalPrincipalStore::TABLE);
        $uuid = SqlPlatform::PostgreSQL === $context->platform() ? 'UUID' : 'CHAR(36)';
        $context->execute(\sprintf('ALTER TABLE %s ADD COLUMN owner_id %s NULL', $table, $uuid));
        $context->execute(\sprintf('ALTER TABLE %s ADD COLUMN profile TEXT NULL', $table));
        $context->execute(\sprintf('CREATE INDEX %s ON %s (realm, owner_id)', $context->name('principal_owner'), $table));
    }
}
