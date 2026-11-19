<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What commands that change authority give and take (D-51): a package's provider for its own, e.g.
 * policy's for roles, groups, attributes and relations.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise while delegation (M3) shapes it: it may change in a minor
 *               release ([compatibility](docs/compatibility.md)).
 */
interface DeclaresEffects
{
    /**
     * @return list<class-string> Each command it gives the effect of: each one a ChangesAuthority
     */
    public function declares(): array;

    /**
     * The effect of the request's command, one it declares: Effect::none() for one that gives nothing.
     *
     * @throws \Throwable When the effect can't be computed, e.g. a store that's down: never none instead
     */
    public function effectOf(Request $request): Effect;
}
