<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\Storage\DbalVersionStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * The versions of what decisions read, a row per realm and source, so a decision reads every source's
 * in one statement (D-44).
 *
 * @internal
 */
final class CreateVersions implements Migration
{
    public function version(): string
    {
        return '20261123180000_core_versions';
    }

    public function description(): string
    {
        return 'Create the versions of what decisions read, per realm and source';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalVersionStore::TABLE, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('source')->setTypeName(Types::STRING)->setLength(32)->create());
            $table->addColumn(Column::editor()->setUnquotedName('version')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'source')->create());
        });
    }
}
