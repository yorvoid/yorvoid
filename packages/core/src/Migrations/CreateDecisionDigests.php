<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * When each decision record was written, by the database's clock, for those written outside a caller's
 * transaction, and how far each realm's records are digested in its chain (D-42).
 *
 * @internal
 */
final class CreateDecisionDigests implements Migration
{
    public function version(): string
    {
        return '20261117190000_core_decision_digests';
    }

    public function description(): string
    {
        return 'Keep when decision records were written, and how far they are digested';
    }

    public function lockProfile(): LockProfile
    {
        // A nullable column, added without rewriting the table, and a new table.
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $bigint = SqlPlatform::PostgreSQL === $context->platform() ? 'BIGINT' : 'INTEGER';
        $context->execute(\sprintf('ALTER TABLE %s ADD COLUMN written_us %s NULL', $context->table(DbalDecisionLogStore::TABLE), $bigint));
        $context->execute(\sprintf('CREATE INDEX %s ON %s (realm, written_us)', $context->name('authorization_decision_written'), $context->table(DbalDecisionLogStore::TABLE)));
        $context->createTable(DbalDecisionLogStore::DIGESTS, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('digested_until_us')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm')->create());
        });
        $context->createTable(DbalDecisionLogStore::PRUNED, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::SMALLINT)->create());
            $table->addColumn(Column::editor()->setUnquotedName('before_us')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        });
    }
}
