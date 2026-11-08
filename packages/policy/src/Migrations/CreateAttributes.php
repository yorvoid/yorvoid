<?php

declare(strict_types=1);

namespace YorvoID\Policy\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Policy\Storage\DbalAttributeStore;

/**
 * @internal
 */
final class CreateAttributes implements Migration
{
    public function version(): string
    {
        return '20261108030000_policy_attributes';
    }

    public function description(): string
    {
        return 'Create principals\' security attributes';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalAttributeStore::TABLE, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(128)->create());
            $table->addColumn(Column::editor()->setUnquotedName('value')->setTypeName(Types::TEXT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'principal_id', 'name')->create());
        });
    }
}
