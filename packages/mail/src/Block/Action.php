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
        if (!self::isSafe($url)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a web address for an email\'s action.', $url), 'Use an absolute https:// address of your application, without a user name or password in it; http:// only for localhost.', 'concepts/mail.md#emails');
        }
    }

    /**
     * Whether a link may go in an email: https (http only for localhost, in development), and nothing
     * before the host that could make "https://example.com@evil.example" read as example.com.
     */
    public static function isSafe(string $url): bool
    {
        $parts = false === filter_var($url, \FILTER_VALIDATE_URL) ? false : parse_url($url);

        return \is_array($parts)
            && !isset($parts['user']) && !isset($parts['pass'])
            && ('https' === ($parts['scheme'] ?? null) || ('http' === ($parts['scheme'] ?? null) && 'localhost' === ($parts['host'] ?? null)));
    }
}
