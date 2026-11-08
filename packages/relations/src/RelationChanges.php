<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Change\RemoveRelation;

/**
 * Adds and removes relations, each through Operations::run(), in a transaction with its security log
 * entry. Only relations the schema declares, to subjects whose relation it declares too.
 *
 * @api
 */
final readonly class RelationChanges implements OperationHandler
{
    /** The ability to change relations; yorvoid/policy declares it */
    public const string ABILITY = 'yorvoid.relations.manage';

    public function __construct(
        private RelationSchema $schema,
        private RelationStore $store,
        private SecurityLog $log,
        private Transactions $transactions,
    ) {
    }

    public function operations(): array
    {
        return [
            AddRelation::class => $this->add(...),
            RemoveRelation::class => $this->remove(...),
        ];
    }

    /**
     * @throws UnknownRelation
     */
    private function add(AddRelation $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $tuple = $this->tuple($command->realm, $command->object, $command->relation, $command->subject);

        $this->transactions->run(function () use ($tuple, $permit, $origin): void {
            $this->store->add($tuple);
            $this->record('yorvoid.relation.add', $tuple, $permit, $origin);
        });
    }

    /**
     * @return bool Whether the subject had it
     */
    private function remove(RemoveRelation $command, Permit $permit, Origin $origin): bool
    {
        $permit->use($command);
        $tuple = new RelationTuple($command->realm, $command->object, $command->relation, $command->subject);

        return $this->transactions->run(function () use ($tuple, $permit, $origin): bool {
            $had = $this->store->remove($tuple);

            if ($had) {
                $this->record('yorvoid.relation.remove', $tuple, $permit, $origin);
            }

            return $had;
        });
    }

    /**
     * @throws UnknownRelation
     */
    private function tuple(Realm $realm, ResourceRef $object, string $relation, Subject $subject): RelationTuple
    {
        if (null === $this->schema->relation($object->type, $relation)) {
            throw new UnknownRelation($object->type, $relation);
        }

        if (null !== $subject->relation && null === $this->schema->relation($subject->resource->type, $subject->relation)) {
            throw new UnknownRelation($subject->resource->type, $subject->relation);
        }

        return new RelationTuple($realm, $object, $relation, $subject);
    }

    private function record(string $action, RelationTuple $tuple, Permit $permit, Origin $origin): void
    {
        $this->log->record($tuple->realm, $action, Outcome::Success, $origin, $permit->actor->principalId, $tuple->object->type, $tuple->object->id, ['relation' => $tuple->relation, 'subject' => (string) $tuple->subject]);
    }
}
