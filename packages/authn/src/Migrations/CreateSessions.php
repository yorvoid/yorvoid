<?php

declare(strict_types=1);

namespace YorvoID\Authn\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateSessions implements Migration
{
    public function version(): string
    {
        return '20261027220000_authn_sessions';
    }

    public function description(): string
    {
        return 'Create the authentication sessions';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalSessionStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('principal_status_version')->setTypeName(Types::INTEGER)->create());
            $table->addColumn(self::string('token_hash', 64));
            $table->addColumn(Column::editor()->setUnquotedName('authentication')->setTypeName(Types::JSON)->create());
            $table->addColumn(self::time('started_at'));
            $table->addColumn(self::time('last_seen_at'));
            $table->addColumn(self::time('expires_at'));
            $table->addColumn(self::time('idle_expires_at'));
            $table->addColumn(self::string('device', 255, true));
            $table->addColumn(self::string('address', 45, true));
            $table->addColumn(self::time('ended_at', true));
            $table->addColumn(self::string('end_reason', 32, true));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('authentication_session_token'))->setType(Index\IndexType::UNIQUE)->setUnquotedColumnNames('token_hash')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('authentication_session_principal'))->setUnquotedColumnNames('realm', 'principal_id')->create());
        });
    }

    /**
     * @param non-empty-string $name
     */
    private static function string(string $name, int $length, bool $nullable = false): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->setNotNull(!$nullable)->create();
    }

    /**
     * @param non-empty-string $name
     */
    private static function time(string $name, bool $nullable = false): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::DATETIMETZ_IMMUTABLE)->setNotNull(!$nullable)->create();
    }
}
