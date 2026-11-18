<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where failed attempts and refusals are kept, apart from the chained security log: they change
 * nothing, so they're written outside the caller's transaction and take no realm lock (D-42). The
 * decision log is one.
 *
 * @api
 */
interface AttemptLog
{
    /**
     * Keeps a failed attempt or a refusal, as SecurityLog::attempt() checked it.
     *
     * @param array<string, mixed> $details
     */
    public function attempt(Realm $realm, string $action, Outcome $outcome, Origin $origin, ?PrincipalId $actor, ?string $subjectType, ?string $subjectId, array $details): void;
}
