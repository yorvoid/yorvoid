<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Migration;

/**
 * What a migration does to tables in use, declared by the migration itself (PLT-028), so upgrades can
 * run while the previous version keeps serving traffic.
 *
 * @api
 */
enum LockProfile: string
{
    /**
     * Nothing blocks reads or writes for more than an instant: creating tables, adding nullable columns
     * or columns with constant defaults, creating indexes on new tables, inserting rows.
     */
    case Online = 'online';

    /**
     * Blocks reads or writes of a table in use while it runs: rewriting a table, adding an index to a
     * large table without CONCURRENTLY, changing a column's type. Refused unless explicitly allowed, for
     * a maintenance window.
     */
    case Blocking = 'blocking';
}
