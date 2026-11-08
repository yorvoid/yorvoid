<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;

/**
 * One relation (AUZ-011): the subject has the relation to the object, e.g. principal:ada is an editor of
 * page:42, or site:42's parent is server:1.
 *
 * @api
 */
final readonly class RelationTuple implements \Stringable
{
    public function __construct(
        public Realm $realm,
        public ResourceRef $object,
        public string $relation,
        public Subject $subject,
    ) {
        RelationDefinition::check($relation);
    }

    public function equals(self $other): bool
    {
        return $this->realm->equals($other->realm) && $this->object->equals($other->object) && $this->relation === $other->relation && $this->subject->equals($other->subject);
    }

    public function __toString(): string
    {
        return $this->object.'#'.$this->relation.'@'.$this->subject;
    }
}
