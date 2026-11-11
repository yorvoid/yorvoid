<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A policy element that is safe only alongside others, e.g. a permit that lets changes through which
 * forbids must weigh. The evaluator refuses to be built without them.
 *
 * @api
 */
interface RequiresElements
{
    /**
     * @return array<string, string> The names of the elements it needs, each with how to add it
     */
    public function requiredElements(): array;
}
