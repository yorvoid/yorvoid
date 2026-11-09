<?php

declare(strict_types=1);

namespace YorvoID\Policy\ViewAs;

use YorvoID\Core\Operation\ViewingAs;

/**
 * A view-as that began: the actor to decide with, and where the view differs from what the target may
 * do. Decisions with the actor never exceed the viewer, so what's beyond the viewer is refused in the
 * view though the target would be permitted. The host decides: refuse to show such a view (as Quire
 * does), or warn that it's partial.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's view-as uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class ViewAsStart
{
    /**
     * @param list<Beyond> $beyond What the target's roles give and the viewer's don't; empty when the view is the target's whole
     */
    public function __construct(
        public ViewingAs $actor,
        public array $beyond,
    ) {
    }

    /**
     * Whether the view shows all the target may do, as far as the roles tell.
     */
    public function complete(): bool
    {
        return [] === $this->beyond;
    }
}
