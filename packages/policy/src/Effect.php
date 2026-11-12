<?php

declare(strict_types=1);

namespace YorvoID\Policy;

/**
 * What a change gives and what it takes, from anyone (M2-13), each part in the order it's weighed.
 *
 * @internal
 */
final readonly class Effect
{
    /**
     * @param list<EffectPart> $gives
     * @param list<EffectPart> $takes
     */
    public function __construct(
        public array $gives = [],
        public array $takes = [],
    ) {
    }
}
