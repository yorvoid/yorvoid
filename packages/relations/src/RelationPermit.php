<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * The permit by relations: a request for an ability on a resource is let through when the actor has a
 * relation to the resource that gives the ability, e.g. an editor of the page edits it. For a batch,
 * each relation is checked for every resource at once: one store call per level.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application uses it directly: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class RelationPermit implements PermitElement, Preloads
{
    /** @var \WeakMap<Request, array<string, bool|null>> By request, what each relation gave */
    private \WeakMap $preloaded;

    public function __construct(
        private Relations $relations,
    ) {
        $this->preloaded = new \WeakMap();
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

    public function preload(array $requests): void
    {
        /** @var array<string, array<string, array<string, array{Realm, PrincipalId, string, list<array{Request, ResourceRef}>}>>> $batches By realm, principal and relation */
        $batches = [];

        foreach ($requests as $request) {
            $resource = $request->resource;
            $principal = $request->actor->principalId;
            \assert(null !== $resource && null !== $request->ability && null !== $principal);

            foreach ($this->relations->schema()->granting($resource->type, $request->ability) as $relation) {
                $batches[$request->realm->name][(string) $principal][$relation] ??= [$request->realm, $principal, $relation, []];
                $batches[$request->realm->name][(string) $principal][$relation][3][] = [$request, $resource];
            }
        }

        foreach ($batches as $principals) {
            foreach ($principals as $relations) {
                foreach ($relations as [$realm, $principal, $relation, $batch]) {
                    $has = $this->relations->checkMany($realm, array_column($batch, 1), $relation, Subject::principal($principal));

                    foreach ($batch as [$request, $resource]) {
                        $this->preloaded[$request] = [...$this->preloaded[$request] ?? [], $relation => $has[(string) $resource]];
                    }
                }
            }
        }
    }

    public function evaluate(Request $request): Truth
    {
        $resource = $request->resource;
        $ability = $request->ability;
        $principal = $request->actor->principalId;
        \assert(null !== $resource && null !== $ability && null !== $principal);
        $tooDeep = false;

        foreach ($this->relations->schema()->granting($resource->type, $ability) as $relation) {
            $preloaded = $this->preloaded[$request] ?? [];
            $has = \array_key_exists($relation, $preloaded) ? $preloaded[$relation] : $this->relations->check($request->realm, $resource, $relation, Subject::principal($principal));

            if (true === $has) {
                return Truth::holds(\sprintf('They\'re %s of %s, which gives "%s".', $relation, $resource, $ability), Ground::relation($resource, $relation));
            }

            $tooDeep = $tooDeep || null === $has;
        }

        return $tooDeep
            ? Truth::unknown(IndeterminateReason::EvaluationError, \sprintf('Their relations to %s go deeper than they\'re followed.', $resource))
            : Truth::fails(\sprintf('They have no relation to %s that gives "%s".', $resource, $ability));
    }
}
