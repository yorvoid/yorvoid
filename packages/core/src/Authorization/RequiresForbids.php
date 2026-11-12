<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A policy element, or an operation handler, that is safe only alongside some forbids: e.g. a permit, or
 * the handler of changes, that lets through what the forbids must weigh. The evaluator, and Operations
 * with the handler, refuse to be built without them.
 *
 * @api
 */
interface RequiresForbids
{
    /**
     * @return array<string, string> The names of the forbids it needs, each with how to add it
     */
    public function requiredForbids(): array;
}
