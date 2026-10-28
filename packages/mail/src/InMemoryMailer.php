<?php

declare(strict_types=1);

namespace YorvoID\Mail;

/**
 * Keeps the emails it's given instead of sending them: for tests, and for previews.
 *
 * @api
 */
final class InMemoryMailer implements Mailer
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(Email $email): void
    {
        $this->sent[] = $email;
    }
}
