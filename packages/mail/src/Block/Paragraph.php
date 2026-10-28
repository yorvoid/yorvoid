<?php

declare(strict_types=1);

namespace YorvoID\Mail\Block;

/**
 * @api
 */
final readonly class Paragraph implements Block
{
    public function __construct(
        public string $text,
    ) {
    }
}
