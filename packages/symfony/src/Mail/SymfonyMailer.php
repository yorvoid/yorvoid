<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Mail;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use YorvoID\Mail\Email;
use YorvoID\Mail\Mailer;
use YorvoID\Mail\Renderer;

/**
 * Sends YorvoID's emails through Symfony Mailer, as plain text and HTML.
 *
 * @api
 */
final readonly class SymfonyMailer implements Mailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Address $from,
        private Renderer $renderer = new Renderer(),
    ) {
    }

    public function send(Email $email): void
    {
        $this->mailer->send(new \Symfony\Component\Mime\Email()
            ->from($this->from)
            ->to($email->to)
            ->subject($email->subject)
            ->text($this->renderer->text($email))
            ->html($this->renderer->html($email)));
    }
}
