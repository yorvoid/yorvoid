<?php

declare(strict_types=1);

namespace YorvoID\Authn\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateRecovery implements Migration
{
    public function version(): string
    {
        return '20261028180000_authn_recovery';
    }

    public function description(): string
    {
        return 'Create recovery codes and the cool-downs after recoveries';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalRecoveryCodeStore::TABLE, static function (TableEditor $table): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('code_hash', 64));
            $table->addColumn(self::time('created_at'));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'principal_id', 'code_hash')->create());
        });

        $context->createTable(DbalCoolDownStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::time('ends_at'));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'principal_id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('recovery_cool_down_end'))->setUnquotedColumnNames('ends_at')->create());
        });
    }

    /**
     * @param non-empty-string $name
     */
    private static function string(string $name, int $length): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->create();
    }

    /**
     * @param non-empty-string $name
     */
    private static function time(string $name): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create();
    }
}
