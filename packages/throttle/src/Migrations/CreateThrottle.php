<?php

declare(strict_types=1);

namespace YorvoID\Throttle\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Throttle\Storage\DbalThrottleStore;

/**
 * @internal
 */
final class CreateThrottle implements Migration
{
    public function version(): string
    {
        return '20261028190000_throttle';
    }

    public function description(): string
    {
        return 'Create the throttle\'s keys';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalThrottleStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('key_hash')->setTypeName(Types::STRING)->setLength(64)->create());
            $table->addColumn(Column::editor()->setUnquotedName('clear_at')->setTypeName(Types::BIGINT)->create());
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('key_hash')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('throttle_key_clear'))->setUnquotedColumnNames('clear_at')->create());
        });
    }
}
