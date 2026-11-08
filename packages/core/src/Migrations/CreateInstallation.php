<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\UuidV4;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateInstallation implements Migration
{
    public function version(): string
    {
        return '20261108140000_core_installation';
    }

    public function description(): string
    {
        return 'Create the installation\'s ID, which a shared cache tells installations apart by';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(Installation::TABLE, static function (TableEditor $table): void {
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        });
        // Random, not derived from anything an installation shares with another, such as its path.
        $context->execute(\sprintf('INSERT INTO %s (id) VALUES (\'%s\')', $context->table(Installation::TABLE), new UuidV4()->toRfc4122()));
    }
}
