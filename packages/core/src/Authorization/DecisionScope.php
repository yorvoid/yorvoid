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
 * It also collects the attributes resolvers give while each decision judges (M-9), one value a name: a
 * second read of a name in the same decision is given the first, so a decision reads one state of them
 * too, and they're recorded with it.
 *
 * The evaluator opens it; one shared instance is given to it, the cached stores and the attributes.
 *
 * @api
 *
 * @experimental
 */
final class DecisionScope
{
    /** @var list<array{string, array<string, int>}|null> The decisions being judged, innermost last; null where none is */
    private array $frames = [];

    /** @var list<array<string, array{string|int|bool|list<string>, bool}>> For each decision being judged, innermost last: by name, the value read and whether it's public */
    private array $attributes = [];

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
     * The value an attribute was given in the decision being judged, if it was read in it.
     *
     * @return string|int|bool|list<string>|null
     */
    public function attribute(string $name): string|int|bool|array|null
    {
        $key = array_key_last($this->attributes);

        return null === $key ? null : ($this->attributes[$key][$name][0] ?? null);
    }

    /**
     * An attribute the decision being judged read, from a resolver: kept for its record. Outside a
     * decision, nothing is kept.
     *
     * @param string|int|bool|list<string> $value
     * @param bool                         $public Whether its resolver says it isn't personal data
     */
    public function read(string $name, string|int|bool|array $value, bool $public): void
    {
        $key = array_key_last($this->attributes);

        if (null !== $key) {
            $this->attributes[$key][$name] ??= [$value, $public];
        }
    }

    /**
     * Judges one decision, collecting the attributes it reads.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return array{T, array<string, array{string|int|bool|list<string>, bool}>} What it gave, and the attributes it read
     *
     * @internal
     */
    public function judging(\Closure $work): array
    {
        $at = \count($this->attributes);
        $this->attributes[] = [];

        try {
            $result = $work();

            return [$result, $this->attributes[$at] ?? []];
        } finally {
            array_pop($this->attributes);
        }
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
