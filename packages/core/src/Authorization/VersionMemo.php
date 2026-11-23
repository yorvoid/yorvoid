<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Duration;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\ForgetsWhatItRead;

/**
 * An opt-in memo of a realm's versions for the request, for showing: an ability decided without the
 * guard, e.g. a page's "can edit" links, decides at the versions the request's first such decision read,
 * rather than reading them again each time. Off by default (yorvoid.authorization.version_memo: request).
 *
 * They're kept for a short time only (2 seconds by default), and read again after it, so a long command
 * or a worker that never resets it still sees a revocation within that time: what a page shows can be as
 * old as that, and no older.
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
    /** @var array<string, array{array<string, int>, \DateTimeImmutable}> By realm: the versions, and when they were read */
    private array $versions = [];

    private readonly Duration $maxAge;

    /**
     * @param Duration|null $maxAge How long the versions read are kept, at most: 2 seconds if not given
     */
    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
        ?Duration $maxAge = null,
    ) {
        $this->maxAge = $maxAge ?? Duration::seconds(2);
    }

    /**
     * The realm's versions as the request first read them, read now if it hasn't or if they're older
     * than the max age.
     *
     * @param \Closure(): array<string, int> $read
     *
     * @return array<string, int>
     */
    public function versions(Realm $realm, \Closure $read): array
    {
        $now = $this->clock->now();
        $kept = $this->versions[$realm->name] ?? null;

        if (null !== $kept && $this->maxAge->after($kept[1]) > $now) {
            return $kept[0];
        }

        $versions = $read();
        $this->versions[$realm->name] = [$versions, $now];

        return $versions;
    }

    /**
     * Forgets them: between requests, and between a worker's messages.
     */
    public function reset(): void
    {
        $this->versions = [];
    }
}
