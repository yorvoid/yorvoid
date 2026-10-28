<?php

declare(strict_types=1);

namespace YorvoID\Authn\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Session\Storage\DbalKnownDeviceStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateKnownDevices implements Migration
{
    public function version(): string
    {
        return '20261028200000_authn_known_devices';
    }

    public function description(): string
    {
        return 'Create the devices principals signed in from';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalKnownDeviceStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('realm')->setTypeName(Types::STRING)->setLength(63)->create());
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('cookie_hash')->setTypeName(Types::STRING)->setLength(64)->create());
            $table->addColumn(Column::editor()->setUnquotedName('first_seen_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(Column::editor()->setUnquotedName('last_seen_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'principal_id', 'cookie_hash')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('known_device_last_seen'))->setUnquotedColumnNames('last_seen_at')->create());
        });
    }
}
