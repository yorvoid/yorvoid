<?php

declare(strict_types=1);

namespace YorvoID\Mail\Block;

/**
 * Labelled values, e.g. when and from which device something happened.
 *
 * @api
 */
final readonly class Facts implements Block
{
    /**
     * @param array<string, string> $facts Label => value, in order
     */
    public function __construct(
        public array $facts,
    ) {
    }
}
