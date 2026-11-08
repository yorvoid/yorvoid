<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * The permit by relations: a request for an ability on a resource is let through when the actor has a
 * relation to the resource that gives the ability, e.g. an editor of the page edits it.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application uses it directly: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class RelationPermit implements PermitElement
{
    public function __construct(
        private Relations $relations,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.relations';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    public function matches(Request $request): bool
    {
        return null !== $request->ability && null !== $request->resource && $request->actorInRealm()
            && [] !== $this->relations->schema()->granting($request->resource->type, $request->ability);
    }

    public function evaluate(Request $request): Truth
    {
        $resource = $request->resource;
        $ability = $request->ability;
        $principal = $request->actor->principalId;
        \assert(null !== $resource && null !== $ability && null !== $principal);
        $tooDeep = false;

        foreach ($this->relations->schema()->granting($resource->type, $ability) as $relation) {
            $has = $this->relations->check($request->realm, $resource, $relation, Subject::principal($principal));

            if (true === $has) {
                return Truth::holds(\sprintf('They\'re %s of %s, which gives "%s".', $relation, $resource, $ability));
            }

            $tooDeep = $tooDeep || null === $has;
        }

        return $tooDeep
            ? Truth::unknown(IndeterminateReason::EvaluationError, \sprintf('Their relations to %s go deeper than they\'re followed.', $resource))
            : Truth::fails(\sprintf('They have no relation to %s that gives "%s".', $resource, $ability));
    }
}
