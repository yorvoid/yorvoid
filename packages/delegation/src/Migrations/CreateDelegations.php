<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Delegation\Storage\DbalDelegationStore;

/**
 * Delegations, a row each, found by delegate, delegator, root and parent within their realm.
 *
 * @internal
 */
final class CreateDelegations implements Migration
{
    public function version(): string
    {
        return '20261123180300_delegation';
    }

    public function description(): string
    {
        return 'Create delegations';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalDelegationStore::TABLE, static function (TableEditor $table) use ($context): void {
            foreach ([
                ['id', Types::GUID], ['realm', Types::STRING, 63], ['delegator', Types::GUID], ['delegate', Types::GUID], ['parent', Types::GUID, null, true],
                ['root', Types::GUID], ['depth', Types::INTEGER], ['actions', Types::TEXT], ['patterns', Types::TEXT], ['resources', Types::TEXT],
                ['purpose', Types::STRING, 255, true], ['not_before', Types::DATETIMETZ_IMMUTABLE], ['expires_at', Types::DATETIMETZ_IMMUTABLE],
                ['redelegable', Types::TEXT], ['remaining_depth', Types::INTEGER], ['execution', Types::STRING, 255, true], ['step_up', Types::TEXT, null, true],
                ['issued_by', Types::STRING, 64], ['issued_at_version', Types::BIGINT], ['issued_at', Types::DATETIMETZ_IMMUTABLE], ['status', Types::STRING, 16],
                ['revoked_at', Types::DATETIMETZ_IMMUTABLE, null, true], ['revoked_by', Types::GUID, null, true], ['revoked_why', Types::STRING, 255, true],
            ] as $column) {
                $table->addColumn(self::column(...$column));
            }

            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());

            foreach (['delegate', 'delegator', 'root', 'parent'] as $column) {
                $table->addIndex(Index::editor()->setUnquotedName($context->name('delegation_'.$column))->setUnquotedColumnNames('realm', $column)->create());
            }
        });
    }

    /**
     * @param non-empty-string $name
     */
    private static function column(string $name, string $type, ?int $length = null, bool $null = false): Column
    {
        $editor = Column::editor()->setUnquotedName($name)->setTypeName($type)->setNotNull(!$null);

        return (null === $length ? $editor : $editor->setLength($length))->create();
    }
}
