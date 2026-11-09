<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\IntegratorError;

/**
 * A fact on an ordered scale (XP-15), e.g. a readiness level: its scale, and its value when it's known,
 * else the last value that was. Higher is worse, by convention: a host whose scale runs the other way
 * (DEFCON 1 is the most severe) maps it so that it does.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's ordered conditions use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class OrderedFact
{
    /**
     * @param int|null $value     The value now, if it's known
     * @param int|null $lastKnown The last value known, when it isn't now; null for none
     *
     * @throws IntegratorError When the scale is empty, or a value is off it
     */
    private function __construct(
        public string $name,
        public int $min,
        public int $max,
        public ?int $value,
        public ?int $lastKnown,
    ) {
        if ($min >= $max) {
            throw new IntegratorError(\sprintf('The scale of "%s" runs from %d to %d.', $name, $min, $max), 'Give a scale from a lower value to a higher one, higher being worse.', 'concepts/authorization.md#ordered-conditions');
        }

        foreach ([$value, $lastKnown] as $one) {
            if (null !== $one && ($one < $min || $one > $max)) {
                throw new IntegratorError(\sprintf('"%s" is %d, off its scale from %d to %d.', $name, $one, $min, $max), 'Give a value on the scale.', 'concepts/authorization.md#ordered-conditions');
            }
        }
    }

    public static function known(string $name, int $min, int $max, int $value): self
    {
        return new self($name, $min, $max, $value, null);
    }

    /**
     * @param int|null $lastKnown The last value known, if any
     */
    public static function unknown(string $name, int $min, int $max, ?int $lastKnown = null): self
    {
        return new self($name, $min, $max, null, $lastKnown);
    }

    /**
     * The values it may have now: itself when it's known; otherwise, taking the worst, every value from
     * the last known one (or the scale's lowest, with none) to the scale's highest.
     *
     * @return array{int, int} The lowest and the highest
     */
    public function possible(): array
    {
        return null === $this->value ? [$this->lastKnown ?? $this->min, $this->max] : [$this->value, $this->value];
    }
}
