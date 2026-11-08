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
 * Gives a subject a relation to a resource, e.g. makes someone an editor of a page; giving it again changes nothing.
 *
 * @implements Command<null>
 *
 * @api
 */
#[Operation('yorvoid.relation.add', 'Relate a subject to a resource', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: RelationChanges::ABILITY, inverse: 'yorvoid.relation.remove')]
final readonly class AddRelation implements Command, ConcernsResource
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
