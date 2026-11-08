<?php

declare(strict_types=1);

namespace YorvoID\Relations\Change;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Relations\RelationChanges;
use YorvoID\Relations\Subject;

/**
 * Takes a relation from a subject.
 *
 * @implements Command<bool>
 *
 * @api
 */
#[Operation('yorvoid.relation.remove', 'Remove a relation', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: RelationChanges::ABILITY)]
final readonly class RemoveRelation implements Command, ConcernsResource
{
    public function __construct(
        public Realm $realm,
        public ResourceRef $object,
        public string $relation,
        public Subject $subject,
    ) {
    }

    public function resource(): ResourceRef
    {
        return $this->object;
    }
}
