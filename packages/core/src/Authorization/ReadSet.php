<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What a decision read (D-44): each source's version, read before the elements judged, and the sources
 * that changed while they judged. A decision that read a moved source read data at least as new as its
 * versions, never older; whatever acts on it checks again, so it's never wrong in the dangerous direction.
 *
 * What moved is checked only where something uses it: an operation's decision, a refusal, a decision
 * that's recorded or seen as someone else. An ability's Permit that's neither recorded nor acted on
 * isn't checked, and says so: its moved sources are unknown (null), never none.
 *
 * @api
 */
final readonly class ReadSet
{
    /**
     * @param array<string, int>         $versions   By source, read before judging, e.g. ["policy" => 12, "relations" => 4]
     * @param list<string>|null          $moved      The sources whose version changed while it judged; null when that wasn't checked
     * @param array<string, string>|null $attributes Resolved attributes, by name: a value or its MAC; null while they aren't recorded
     */
    public function __construct(
        public array $versions = [],
        public ?array $moved = [],
        public ?array $attributes = null,
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
     * The versions read before judging, with what moved meanwhile unchecked.
     *
     * @param array<string, int> $before
     */
    public static function unchecked(array $before): self
    {
        return new self($before, null);
    }

    /**
     * Whether what moved while it judged was checked.
     */
    public function checked(): bool
    {
        return null !== $this->moved;
    }

    /**
     * Whether nothing it read changed while it judged, as checked: an unchecked read set isn't settled.
     */
    public function settled(): bool
    {
        return [] === $this->moved;
    }
}
