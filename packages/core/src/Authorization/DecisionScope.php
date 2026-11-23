<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Realm;

/**
 * The decision being judged, for the stores it reads (D-44): the versions the evaluator read before its
 * elements judge, so that a cached store reads at them, and a decision reads one state of each source.
 * Outside a decision, there's none: a store reads its version as it is, never one kept for the request
 * or the job, so a revocation stops the next decision in any process.
 *
 * The evaluator opens it; one shared instance is given to it and to the cached stores.
 *
 * @api
 *
 * @experimental
 */
final class DecisionScope
{
    /** @var list<array{string, array<string, int>}|null> The decisions being judged, innermost last; null where none is */
    private array $frames = [];

    /**
     * The version the decision being judged read of a source in this realm, if one is being judged and
     * read it.
     */
    public function version(Realm $realm, string $source): ?int
    {
        $key = array_key_last($this->frames);
        $frame = null === $key ? null : $this->frames[$key];

        if (null === $frame || $frame[0] !== $realm->name) {
            return null;
        }

        return $frame[1][$source] ?? null;
    }

    /**
     * Judges inside a decision that read these versions.
     *
     * @template T
     *
     * @param array<string, int> $versions By source
     * @param \Closure(): T      $work
     *
     * @return T
     *
     * @internal
     */
    public function within(Realm $realm, array $versions, \Closure $work): mixed
    {
        return $this->with([$realm->name, $versions], $work);
    }

    /**
     * Reads as no decision does, e.g. the versions themselves, while one is being judged.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     *
     * @internal
     */
    public function outside(\Closure $work): mixed
    {
        return $this->with(null, $work);
    }

    /**
     * @template T
     *
     * @param array{string, array<string, int>}|null $frame
     * @param \Closure(): T                          $work
     *
     * @return T
     */
    private function with(?array $frame, \Closure $work): mixed
    {
        $this->frames[] = $frame;

        try {
            return $work();
        } finally {
            array_pop($this->frames);
        }
    }
}
