<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;

/**
 * @internal
 */
final class CreatePasskeys implements Migration
{
    public function version(): string
    {
        return '20261028000000_passkeys';
    }

    public function description(): string
    {
        return 'Create passkeys and their ceremonies';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalPasskeyStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('credential_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(Column::editor()->setUnquotedName('principal_id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('webauthn_id_hash', 64));
            $table->addColumn(Column::editor()->setUnquotedName('webauthn_id')->setTypeName(Types::TEXT)->create());
            $table->addColumn(Column::editor()->setUnquotedName('public_key')->setTypeName(Types::TEXT)->create());
            $table->addColumn(self::string('user_handle', 86));
            $table->addColumn(Column::editor()->setUnquotedName('sign_count')->setTypeName(Types::BIGINT)->create());
            $table->addColumn(Column::editor()->setUnquotedName('transports')->setTypeName(Types::JSON)->create());
            $table->addColumn(Column::editor()->setUnquotedName('aaguid')->setTypeName(Types::GUID)->create());
            $table->addColumn(Column::editor()->setUnquotedName('backup_eligible')->setTypeName(Types::BOOLEAN)->create());
            $table->addColumn(Column::editor()->setUnquotedName('backed_up')->setTypeName(Types::BOOLEAN)->create());
            $table->addColumn(Column::editor()->setUnquotedName('user_verification_initialized')->setTypeName(Types::BOOLEAN)->create());
            $table->addColumn(self::string('attestation_type', 32));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('credential_id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('passkey_webauthn_id'))->setType(Index\IndexType::UNIQUE)->setUnquotedColumnNames('realm', 'webauthn_id_hash')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('passkey_principal'))->setUnquotedColumnNames('realm', 'principal_id')->create());
        });

        $context->createTable(DbalCeremonyStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create());
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(self::string('kind', 16));
            $table->addColumn(Column::editor()->setUnquotedName('options')->setTypeName(Types::TEXT)->create());
            $table->addColumn(Column::editor()->setUnquotedName('session_id')->setTypeName(Types::GUID)->setNotNull(false)->create());
            $table->addColumn(self::string('operation', 191, true));
            $table->addColumn(self::time('expires_at'));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('passkey_ceremony_expiry'))->setUnquotedColumnNames('expires_at')->create());
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
