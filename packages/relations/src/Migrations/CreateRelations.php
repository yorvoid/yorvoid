<?php

declare(strict_types=1);

namespace YorvoID\Relations\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Relations\Storage\DbalRelationStore;

/**
 * @internal
 */
final class CreateRelations implements Migration
{
    public function version(): string
    {
        return '20261108010000_relations';
    }

    public function description(): string
    {
        return 'Create the relations between resources';
    }

    public function lockProfile(): LockProfile
    {
        return LockProfile::Online;
    }

    public function up(MigrationContext $context): void
    {
        $context->createTable(DbalRelationStore::TABLE, static function (TableEditor $table) use ($context): void {
            $table->addColumn(self::string('realm', 63));
            $table->addColumn(self::string('object_type', 64));
            $table->addColumn(self::string('object_id', 255));
            $table->addColumn(self::string('relation', 64));
            $table->addColumn(self::string('subject_type', 64));
            $table->addColumn(self::string('subject_id', 255));
            $table->addColumn(self::string('subject_relation', 64));
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('realm', 'object_type', 'object_id', 'relation', 'subject_type', 'subject_id', 'subject_relation')->create());
            $table->addIndex(Index::editor()->setUnquotedName($context->name('relation_tuple_subject'))->setUnquotedColumnNames('realm', 'subject_type', 'subject_id', 'subject_relation', 'relation')->create());
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
