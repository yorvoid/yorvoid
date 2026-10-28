<?php

declare(strict_types=1);

namespace YorvoID\Mail\Block;

/**
 * Small print at the end, e.g. why the reader got the email.
 *
 * @api
 */
final readonly class Footnote implements Block
{
    public function __construct(
        public string $text,
    ) {
    }
}
