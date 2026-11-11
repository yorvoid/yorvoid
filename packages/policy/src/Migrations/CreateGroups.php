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
final class CreateGroups implements Migration
{
    public function version(): string
    {
        return '20261108020000_policy_groups';
    }

    public function description(): string
    {
        return 'Create groups and the roles given to them';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalPolicyStore::GROUP_TABLE, static function (TableEditor $table): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(self::string('name', 32));
            $table->addColumn(self::string('label', 200));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'name')->create());
        });

        $context->createTable(DbalPolicyStore::GROUP_ASSIGNMENT_TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(self::string('group_name', 32));
            $table->addColumn(self::string('role', 32));
            $table->addColumn(self::string('scope', 320));
            $table->addColumn(Column::editor()->setUnquotedName('assigned_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->setNotNull(false)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'group_name', 'role', 'scope')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('policy_group_role_assignment_scope'))->setUnquotedColumnNames('realm', 'scope')->create());
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
