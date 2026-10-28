<?php

declare(strict_types=1);

namespace YorvoID\Authn\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;

/**
 * @internal
 */
final class CreateOneTimeTokens implements Migration
{
    public function version(): string
    {
        return '20261027230000_authn_one_time_tokens';
    }

    public function description(): string
    {
        return 'Create one-time tokens';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalOneTimeTokenStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(self::string('selector', 32));
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('purpose', 64));
            $table->addColumn(self::string('verifier_hash', 64));
            $table->addColumn(self::string('email', 255, true));
            $table->addColumn(self::time('created_at'));
            $table->addColumn(self::time('expires_at'));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('selector')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('one_time_token_principal'))->setUnquotedColumnNames('realm', 'principal_id', 'purpose')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('one_time_token_expiry'))->setUnquotedColumnNames('expires_at')->create());
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
