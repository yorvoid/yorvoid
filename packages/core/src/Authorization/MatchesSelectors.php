<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Authorization\Selector\Atom;

/**
 * Whether a request's resource meets a selector's condition that needs data core doesn't hold: an
 * attribute's value (policy), a relation (relations). The type and the IDs core matches itself. Its
 * answer only narrows: the root is decided too, so a wrong one can't give more than the root may.
 *
 * @spi
 *
 * @experimental
 */
interface MatchesSelectors
{
    /**
     * Whether it answers for this condition.
     */
    public function answers(Atom $atom): bool;

    /**
     * Holds, fails, or unknown when it can't tell.
     */
    public function match(Atom $atom, Request $request): Truth;
}
