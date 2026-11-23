<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Realm;
use YorvoID\Core\Storage\ForgetsWhatItRead;

/**
 * An opt-in memo of a realm's versions for the request, for showing: an ability decided without the
 * guard, e.g. a page's "can edit" links, decides at the versions the request's first such decision read,
 * rather than reading them again each time. Off by default (yorvoid.authorization.version_memo: request).
 *
 * It's never used by an operation, a view as someone else or a batch, which read the versions for each
 * decision; and a decision it gives that refuses is decided again on what's committed now. A permit it
 * gives says so: its read set is pinned. So within the request, a revocation from another process stops
 * the next operation, but not the next link shown: the links can be as old as the request.
 *
 * @api
 */
final class VersionMemo implements ForgetsWhatItRead
{
    /** @var array<string, array<string, int>> By realm */
    private array $versions = [];

    /**
     * The realm's versions as the request first read them, read now if it hasn't.
     *
     * @param \Closure(): array<string, int> $read
     *
     * @return array<string, int>
     */
    public function versions(Realm $realm, \Closure $read): array
    {
        return $this->versions[$realm->name] ??= $read();
    }

    /**
     * Forgets them: between requests, and between a worker's messages.
     */
    public function reset(): void
    {
        $this->versions = [];
    }
}
