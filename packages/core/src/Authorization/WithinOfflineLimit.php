<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Clock\Elapsed;
use YorvoID\Core\Clock\Instant;
use YorvoID\Core\Duration;

/**
 * An offline decider's Permit-offline, only while the time since a mark is under a limit (XP-03,
 * XP-14), e.g. since the link was lost: measured by elapsed time, which can't go backwards, so setting
 * the wall clock back never extends it. It wraps the decider that decides, and can only refuse what that
 * one would allow: never allow anything it doesn't (MP-4). A mark it can't measure from, of another
 * boot, ahead of now, or none, refuses.
 *
 * ```php
 * new WithinOfflineLimit($lostLinkGrants, new BootTime(), fn (Request $request): ?Instant => $link->lostAt(), Duration::hours(4));
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's offline decisions use it: it may change
 *               in a minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class WithinOfflineLimit implements OfflineDecider
{
    /**
     * @param \Closure(Request): ?Instant $since What the limit runs from, for the request: a trusted, stored mark
     */
    public function __construct(
        private OfflineDecider $inner,
        private Elapsed $elapsed,
        private \Closure $since,
        private Duration $limit,
    ) {
    }

    public function decide(Request $request): Truth
    {
        $truth = $this->inner->decide($request);

        if (TruthValue::Holds !== $truth->value) {
            return $truth;
        }

        $mark = ($this->since)($request);
        $elapsed = null === $mark ? null : $this->elapsed->since($mark);

        if (null === $elapsed) {
            return Truth::fails('The offline limit can\'t be measured: no mark of this boot to measure it from.');
        }

        if ($elapsed >= $this->limit->seconds * 1_000_000_000) {
            return Truth::fails(\sprintf('The offline limit of %s has passed.', $this->limit));
        }

        return $truth;
    }
}
