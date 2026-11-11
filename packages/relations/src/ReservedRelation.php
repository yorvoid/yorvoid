<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A relation of a reserved resource type, changed with AddRelation or RemoveRelation: its relations
 * change only through its owner's operations, e.g. a group's members through AddGroupMember.
 *
 * @api
 */
final class ReservedRelation extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly string $type,
    ) {
        parent::__construct(\sprintf('The relations of "%s" change only through its own operations, not AddRelation or RemoveRelation.', $type));
    }
}
