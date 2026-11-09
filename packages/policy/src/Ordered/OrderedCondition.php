<?php

declare(strict_types=1);

namespace YorvoID\Policy\Ordered;

/**
 * A condition on an ordered fact (XP-15): at least, or at most, a value. Only these two: OrderedConditions
 * decides a range of values by its ends, which is right only for a condition that's monotone.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's ordered conditions use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class OrderedCondition implements \Stringable
{
    private function __construct(
        public bool $atLeast,
        public int $bound,
    ) {
    }

    public static function atLeast(int $bound): self
    {
        return new self(true, $bound);
    }

    public static function atMost(int $bound): self
    {
        return new self(false, $bound);
    }

    public function holdsAt(int $value): bool
    {
        return $this->atLeast ? $value >= $this->bound : $value <= $this->bound;
    }

    public function __toString(): string
    {
        return ($this->atLeast ? '≥ ' : '≤ ').$this->bound;
    }
}
