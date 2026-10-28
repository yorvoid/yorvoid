<?php

declare(strict_types=1);

namespace YorvoID\Mail\Block;

use YorvoID\Core\Exception\IntegratorError;

/**
 * What the reader can do, as a link: a button in HTML, the address in plain text.
 *
 * @api
 */
final readonly class Action implements Block
{
    public function __construct(
        public string $label,
        public string $url,
    ) {
        if (!\in_array(parse_url($url, \PHP_URL_SCHEME), ['https', 'http'], true) || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a web address for an email\'s action.', $url), 'Use an absolute https:// address of your application.', 'concepts/mail.md#emails');
        }
    }
}
