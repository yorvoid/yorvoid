<?php

declare(strict_types=1);

namespace YorvoID\Bench;

/**
 * How a benchmark's subject is measured (tools/bench/run.php): warm-up runs first, then iterations of
 * some revolutions each, timed together; an iteration's time is its revolutions' mean.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Measure
{
    public function __construct(
        public int $iterations = 200,
        public int $revolutions = 1,
        public int $warmup = 5,
    ) {
    }
}
