<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support\Itf;

/**
 * A value of a sum type, e.g. Valid or Some(x).
 */
final readonly class ItfVariant
{
    public function __construct(
        public string $tag,
        public mixed $value = [],
    ) {
    }
}
