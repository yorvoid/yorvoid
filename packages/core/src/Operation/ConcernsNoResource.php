<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * An application's own object that a decision reads but that is about no resource, e.g. a draft its
 * rules look at: only roles on the whole realm count for it. Saying so tells it apart from an object
 * that forgot ConcernsResource, which Symfony's isGranted() refuses.
 *
 * @api
 */
interface ConcernsNoResource
{
}
