<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * The locks of D-52, each a write: a row per realm, for a check over the realm as a whole, e.g. that
 * it keeps an administrator, and a counter on each principal, for changes of one principal.
 *
 * @internal
 */
final class CreateLocks implements Migration
{
    public function version(): string
    {
        return '20261120180000_core_locks';
    }

    public function description(): string
    {
        return 'Create the realm lock, and a lock counter on principals';
    }

    public function lockProfile(): LockProfile
    {
        // A new table, and a column with a default, added without rewriting the table.
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalTransactions::REALM_LOCK_TABLE, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('n')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm')->create());
        });
        $context->execute(\sprintf('ALTER TABLE %s ADD COLUMN lock_count BIGINT NOT NULL DEFAULT 0', $context->table(DbalPrincipalStore::TABLE)));
    }
}
