<?php

declare(strict_types=1);

namespace YorvoID\Mail;

/**
 * Delivers emails: the application's way of sending them, e.g. Symfony Mailer, given text and HTML
 * from Renderer.
 *
 * @api
 */
interface Mailer
{
    public function send(Email $email): void;
}
