<?php

declare(strict_types=1);

namespace YorvoID\Mail;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Mail\Block\Block;

/**
 * An email as content: who it's to, its subject, and its body as blocks, already in the reader's
 * language. A Renderer makes plain text and HTML of it; a Mailer delivers it.
 *
 * @api
 */
final readonly class Email
{
    /** @var list<Block> */
    public array $body;

    /**
     * @param array<Block> $body   In order
     * @param string       $locale The language it's written in, e.g. "de", for the HTML's lang attribute
     */
    public function __construct(
        public string $to,
        public string $subject,
        array $body,
        public string $locale = 'en',
    ) {
        if (false === filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t an email address.', $to), 'Send emails to a verified email address of the principal.', 'concepts/mail.md#emails');
        }

        $this->body = array_values($body);
    }
}
