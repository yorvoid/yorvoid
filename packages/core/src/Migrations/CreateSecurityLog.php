<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateSecurityLog implements Migration
{
    public function version(): string
    {
        return '20261027183100_core_security_log';
    }

    public function description(): string
    {
        return 'Create the hash-chained security log';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalSecurityLogStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('sequence')->setTypeName(Types::BIGINT)->create());
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('recorded_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(self::string('action', 191));
            $table->addColumn(self::string('outcome', 16));
            $table->addColumn(self::string('origin_kind', 16));
            $table->addColumn(self::string('origin_detail', 255, true));
            $table->addColumn(self::string('correlation_id', 128));
            $table->addColumn(Column::editor()->setUnquotedName('actor')->setTypeName(Types::GUID)->setNotNull(false)->create());
            $table->addColumn(self::string('subject_type', 63, true));
            $table->addColumn(self::string('subject_id', 191, true));
            $table->addColumn(Column::editor()->setUnquotedName('details')->setTypeName(Types::JSON)->create());
            $table->addColumn(self::string('algorithm', 16));
            $table->addColumn(self::string('previous_hash', 128));
            $table->addColumn(self::string('hash', 128));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'sequence')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('security_log_id'))->setType(Index\IndexType::UNIQUE)->setUnquotedColumnNames('id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('security_log_subject'))->setUnquotedColumnNames('realm', 'subject_type', 'subject_id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('security_log_actor'))->setUnquotedColumnNames('realm', 'actor')->create());
        });

        $context->createTable(DbalSecurityLogStore::HEAD_TABLE, static function (TableEditor $table): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('sequence')->setTypeName(Types::BIGINT)->create());
            $table->addColumn(self::string('hash', 128, true));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm')->create());
        });
    }

    /**
     * @param non-empty-string $name
     */
    private static function string(string $name, int $length, bool $nullable = false): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->setNotNull(!$nullable)->create();
    }
}
