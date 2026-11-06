<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * A command that someone may run on their own account (AuthorizedBy::Self): it says whether it concerns
 * the actor, e.g. the actor's own principal, or the session the actor is.
 *
 * @template-covariant TResult
 *
 * @extends Command<TResult>
 *
 * @api
 */
interface SelfOperation extends Command
{
    public function concerns(Actor $actor): bool;
}
