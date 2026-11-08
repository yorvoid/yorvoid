<?php

declare(strict_types=1);

namespace YorvoID\Relations\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;

/**
 * Relations in the database: one row per tuple, a subject set's relation "" for a plain subject.
 * Indexed by object and by subject, so a level of the check is one indexed query.
 *
 * @api
 */
final readonly class DbalRelationStore implements RelationStore
{
    public const string TABLE = 'relation_tuple';

    public const string VERSION_TABLE = 'relation_version';

    /** Pairs per query: well within every platform's limit on parameters */
    private const int BATCH = 100;

    private const string COLUMNS = 'object_type, object_id, relation, subject_type, subject_id, subject_relation';

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(RelationTuple $tuple): void
    {
        try {
            $this->database->connection->insert($this->table(), ['realm' => $tuple->realm->name, ...self::columns($tuple)]);
        } catch (UniqueConstraintViolationException) {
            // It's there already.
            return;
        }

        $this->bump($tuple->realm);
    }

    public function remove(RelationTuple $tuple): bool
    {
        if (0 === $this->database->connection->delete($this->table(), ['realm' => $tuple->realm->name, ...self::columns($tuple)])) {
            return false;
        }

        $this->bump($tuple->realm);

        return true;
    }

    public function subjects(Realm $realm, array $of): array
    {
        $tuples = [];

        foreach (array_chunk($of, self::BATCH) as $batch) {
            $conditions = [];
            $parameters = [$realm->name];

            foreach ($batch as [$object, $relation]) {
                $conditions[] = '(object_type = ? AND object_id = ? AND relation = ?)';
                array_push($parameters, $object->type, $object->id, $relation);
            }

            array_push($tuples, ...$this->tuples($realm, \sprintf('realm = ? AND (%s)', implode(' OR ', $conditions)), $parameters));
        }

        return self::sorted($tuples);
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        $tuples = [];

        foreach (array_chunk($subjects, self::BATCH) as $batch) {
            $conditions = [];
            $parameters = [$realm->name, $relation];

            foreach ($batch as $subject) {
                $conditions[] = '(subject_type = ? AND subject_id = ? AND subject_relation = ?)';
                array_push($parameters, $subject->resource->type, $subject->resource->id, $subject->relation ?? '');
            }

            array_push($tuples, ...$this->tuples($realm, \sprintf('realm = ? AND relation = ? AND (%s)', implode(' OR ', $conditions)), $parameters));
        }

        return self::sorted($tuples);
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return self::sorted($this->tuples($realm, 'realm = ? AND object_type = ? AND object_id = ?', [$realm->name, $object->type, $object->id]));
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        $removed = $this->database->connection->delete($this->table(), ['realm' => $realm->name, 'object_type' => $resource->type, 'object_id' => $resource->id])
            + $this->database->connection->delete($this->table(), ['realm' => $realm->name, 'subject_type' => $resource->type, 'subject_id' => $resource->id]);

        if (0 < $removed) {
            $this->bump($realm);
        }
    }

    public function version(Realm $realm): int
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT version FROM %s WHERE realm = ?', $this->database->table(self::VERSION_TABLE)), [$realm->name]);

        return false === $row ? 0 : Row::int($row, 'version');
    }

    /**
     * The update holds the realm's row until the transaction ends, as the policy version's does.
     */
    private function bump(Realm $realm): void
    {
        $table = $this->database->table(self::VERSION_TABLE);
        $increment = \sprintf('UPDATE %s SET version = version + 1 WHERE realm = ?', $table);

        if (0 === $this->database->connection->executeStatement($increment, [$realm->name])) {
            try {
                $this->database->connection->insert($table, ['realm' => $realm->name, 'version' => 1]);
            } catch (UniqueConstraintViolationException) {
                // Another change made the row first.
                $this->database->connection->executeStatement($increment, [$realm->name]);
            }
        }
    }

    /**
     * @param list<string> $parameters
     *
     * @return list<RelationTuple>
     */
    private function tuples(Realm $realm, string $where, array $parameters): array
    {
        $tuples = [];

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT %s FROM %s WHERE %s', self::COLUMNS, $this->table(), $where), $parameters) as $row) {
            $relation = Row::string($row, 'subject_relation');
            $tuples[] = new RelationTuple(
                $realm,
                new ResourceRef(Row::string($row, 'object_type'), Row::string($row, 'object_id')),
                Row::string($row, 'relation'),
                new Subject(new ResourceRef(Row::string($row, 'subject_type'), Row::string($row, 'subject_id')), '' === $relation ? null : $relation),
            );
        }

        return $tuples;
    }

    /**
     * @return array<string, string>
     */
    private static function columns(RelationTuple $tuple): array
    {
        return [
            'object_type' => $tuple->object->type,
            'object_id' => $tuple->object->id,
            'relation' => $tuple->relation,
            'subject_type' => $tuple->subject->resource->type,
            'subject_id' => $tuple->subject->resource->id,
            'subject_relation' => $tuple->subject->relation ?? '',
        ];
    }

    /**
     * @param list<RelationTuple> $tuples
     *
     * @return list<RelationTuple> As written, e.g. page:1#editor@principal:ada, sorted
     */
    private static function sorted(array $tuples): array
    {
        usort($tuples, static fn (RelationTuple $a, RelationTuple $b): int => (string) $a <=> (string) $b);

        return $tuples;
    }

    private function table(): string
    {
        return $this->database->table(self::TABLE);
    }
}
