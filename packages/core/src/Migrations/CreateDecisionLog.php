<?php

declare(strict_types=1);

namespace YorvoID\Core\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateDecisionLog implements Migration
{
    public function version(): string
    {
        return '20261108130000_core_decision_log';
    }

    public function description(): string
    {
        return 'Create the decision log, apart from the security log';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalDecisionLogStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('decided_at')->setTypeName(Types::DATETIMETZ_IMMUTABLE)->create());
            $table->addColumn(self::string('name', 191));
            $table->addColumn(Column::editor()->setUnquotedName('actor')->setTypeName(Types::GUID)->setNotNull(false)->create());
            $table->addColumn(self::string('verdict', 16));
            // Text, not JSON: kept exactly as hashed.
            $table->addColumn(Column::editor()->setUnquotedName('content')->setTypeName(Types::TEXT)->create());
            $table->addColumn(self::string('hash', 128));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('authorization_decision_time'))->setUnquotedColumnNames('decided_at')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('authorization_decision_actor'))->setUnquotedColumnNames('realm', 'actor', 'decided_at')->create());
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
