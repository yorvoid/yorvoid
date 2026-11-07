<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Realm;

/**
 * An operation's command: what to do, with every parameter, in one realm. Its class has #[Operation].
 *
 * @template-covariant TResult What running it returns
 *
 * @api
 */
interface Command
{
    public Realm $realm { get; }
}
