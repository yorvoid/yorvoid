<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A relation a resource type doesn't declare.
 *
 * @api
 */
final class UnknownRelation extends \InvalidArgumentException implements YorvoIDException
{
    public function __construct(
        public readonly string $type,
        public readonly string $relation,
    ) {
        parent::__construct(\sprintf('The type "%s" has no relation "%s".', $type, $relation));
    }
}
