<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Explanation;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Operation\Decision;

/**
 * Why an actor may not do something, answered from the policy (AUZ-021): the decision and its
 * explanation, what the actor could do about it, and what would permit it. It names roles and
 * relations, never who has them.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's explainer uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class WhyNot
{
    /**
     * @param list<Remedy> $remedies   What the actor could do, e.g. prove who they are again
     * @param list<string> $roles      The roles that have the ability in the realm
     * @param list<string> $superRoles The super roles, which have it too unless it's strict
     * @param list<string> $relations  The relations to the resource that give it
     * @param list<string> $forbids    The forbids that held: they'd refuse it whatever else changed
     */
    public function __construct(
        public Decision $decision,
        public Explanation $explanation,
        public array $remedies,
        public array $roles,
        public array $superRoles,
        public array $relations,
        public array $forbids,
    ) {
    }
}
