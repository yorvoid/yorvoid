<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

/**
 * A decision-log store that can say it writes on a connection of its own: what it adds then outlasts the
 * application's transaction, and DecisionLog writes refusals at once rather than after that transaction
 * ends (D-42). It only ever inserts decision records there, never changes state (I1).
 *
 * @api
 */
interface WritesApart
{
    /**
     * Whether what it writes commits on its own, out of the application's transactions.
     */
    public function writesApart(): bool;
}
