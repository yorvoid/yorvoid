<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreatePrincipals implements Migration
{
    public function version(): string
    {
        return '20261027183000_core_principals';
    }

    public function description(): string
    {
        return 'Create the principal registry and identifiers';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalPrincipalStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('kind')->setTypeName(Types::STRING)->setLength(16)->create());
            $table->addColumn(Column::editor()->setUnquotedName('status')->setTypeName(Types::STRING)->setLength(16)->create());
            $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('status_changed_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('status_version')->setTypeName(Types::INTEGER)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('principal_realm'))->setUnquotedColumnNames('realm', 'id')->create());
        });

        $context->createTable(DbalIdentifierStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('kind')->setTypeName(Types::STRING)->setLength(16)->create());
            $table->addColumn(Column::editor()->setUnquotedName('normalized')->setTypeName(Types::STRING)->setLength(255)->create());
            $table->addColumn(Column::editor()->setUnquotedName('value')->setTypeName(Types::STRING)->setLength(255)->create());
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('verified_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->setNotNull(false)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'kind', 'normalized')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('principal_identifier_principal'))->setType(Index\IndexType::UNIQUE)->setUnquotedColumnNames('principal_id', 'kind')->create());
        });
    }
}
