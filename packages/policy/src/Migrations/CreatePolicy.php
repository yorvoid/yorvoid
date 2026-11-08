<?php

declare(strict_types=1);

namespace YorvoID\Policy\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Policy\Storage\DbalPolicyStore;

/**
 * @internal
 */
final class CreatePolicy implements Migration
{
    public function version(): string
    {
        return '20261107220000_policy';
    }

    public function description(): string
    {
        return 'Create the policy: roles created in realms, changes to the defaults, role assignments, versions';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalPolicyStore::ROLE_TABLE, static function (TableEditor $table): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(self::string('name', 32));
            $table->addColumn(self::string('label', 200));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'name')->create());
        });

        $context->createTable(DbalPolicyStore::CHANGE_TABLE, static function (TableEditor $table): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(self::string('ability', 128));
            $table->addColumn(self::string('role', 32));
            $table->addColumn(self::string('kind', 8));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'ability', 'role')->create());
        });

        $context->createTable(DbalPolicyStore::ASSIGNMENT_TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('role', 32));
            $table->addColumn(self::string('scope', 320));
            $table->addColumn(Column::editor()->setUnquotedName('assigned_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->setNotNull(false)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'principal_id', 'role', 'scope')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('policy_role_assignment_role'))->setUnquotedColumnNames('realm', 'role')->create());
        });

        $context->createTable(DbalPolicyStore::VERSION_TABLE, static function (TableEditor $table): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('version')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm')->create());
        });
    }

    /**
     * @param non-empty-string $name
     */
    private static function string(string $name, int $length): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->create();
    }
}
