<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

/**
 * An invariant that every change to a principal must keep, whichever command, page or console command
 * makes it (IDN-016): "at least one active owner", Quire's "the last administrator can't be suspended".
 *
 * A rule sees the change after it's written, inside its transaction, while other changes in the realm
 * wait: so two administrators suspending the last two owners at the same moment can't both succeed.
 * It reads the state as it will be if the change commits, and returns why that state isn't allowed,
 * or null.
 *
 * ```php
 * final readonly class KeepAnOwner implements GuardRule
 * {
 *     public function check(PrincipalChange $change): ?string
 *     {
 *         return $this->owners->isOwner($change->before) && 0 === $this->owners->countActive($change->after->realm)
 *             ? 'The last active owner can\'t be suspended or deleted.'
 *             : null;
 *     }
 * }
 * ```
 *
 * @api
 */
interface GuardRule
{
    /**
     * @return string|null Why the change isn't allowed, for the person who tried; null if it is
     */
    public function check(PrincipalChange $change): ?string;
}
