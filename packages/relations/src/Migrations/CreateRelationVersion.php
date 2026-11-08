<?php

declare(strict_types=1);

namespace YorvoID\Relations\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Relations\Storage\DbalRelationStore;

/**
 * @internal
 */
final class CreateRelationVersion implements Migration
{
    public function version(): string
    {
        return '20261108120000_relation_version';
    }

    public function description(): string
    {
        return 'Create the relations\' version per realm';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalRelationStore::VERSION_TABLE, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('version')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm')->create());
        });
    }
}
