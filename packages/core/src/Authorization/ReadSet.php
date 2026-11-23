<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What a decision read (D-44): each source's version, read before the elements judged, and the sources
 * that changed while they judged. A decision that read a moved source read data at least as new as its
 * versions, never older; whatever acts on it checks again, so it's never wrong in the dangerous direction.
 *
 * @api
 */
final readonly class ReadSet
{
    /**
     * @param array<string, int>    $versions   By source, read before judging, e.g. ["policy" => 12, "relations" => 4]
     * @param list<string>          $moved      The sources whose version changed while it judged
     * @param array<string, string> $attributes Resolved attributes, by name: a value or its MAC (empty until they're recorded)
     */
    public function __construct(
        public array $versions = [],
        public array $moved = [],
        public array $attributes = [],
    ) {
    }

    /**
     * The versions read before judging, and those read after: a source whose version differs, or that
     * could be read only once, moved.
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    public static function between(array $before, array $after): self
    {
        $moved = [];

        foreach ($before as $source => $version) {
            if (($after[$source] ?? null) !== $version) {
                $moved[] = $source;
            }
        }

        sort($moved, \SORT_STRING);

        return new self($before, $moved);
    }

    /**
     * Whether nothing it read changed while it judged.
     */
    public function settled(): bool
    {
        return [] === $this->moved;
    }
}
