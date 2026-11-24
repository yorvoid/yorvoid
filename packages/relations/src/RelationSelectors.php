<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\MatchesSelectors;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Selector\Atom;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Truth;

/**
 * A delegation's condition on a relation: the subject has the relation to the request's resource, e.g.
 * the delegator is an editor of the page, as the relations say now, implied and inherited ones too.
 *
 * @api
 *
 * @experimental
 */
final readonly class RelationSelectors implements MatchesSelectors
{
    public function __construct(
        private Relations $relations,
    ) {
    }

    public function answers(Atom $atom): bool
    {
        return $atom instanceof RelatedTo;
    }

    public function match(Atom $atom, Request $request): Truth
    {
        \assert($atom instanceof RelatedTo);

        if (null === $request->resource) {
            return Truth::fails('No resource to be related to.');
        }

        $related = $this->relations->check($request->realm, $request->resource, $atom->relation, new Subject($atom->subject));

        return match ($related) {
            true => Truth::holds(\sprintf('It has the relation "%s" the delegation names.', $atom->relation)),
            false => Truth::fails(\sprintf('It hasn\'t the relation "%s" the delegation names.', $atom->relation)),
            null => Truth::unknown(IndeterminateReason::MissingData, 'Whether the relation holds couldn\'t be established.'),
        };
    }
}
