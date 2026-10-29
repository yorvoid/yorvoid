<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Whether security notifications can leave: an address to send them from, and a transport that
 * delivers. It sends nothing.
 *
 * @internal
 */
final readonly class MailerCheck implements Check
{
    /**
     * @param array<string, string>|null $transports Symfony Mailer's transports' DSNs, by name, the default first;
     *                                               null without Symfony Mailer
     */
    public function __construct(
        private bool $notifications,
        private ?string $from,
        private ?array $transports,
        private bool $debug,
    ) {
    }

    public function findings(): iterable
    {
        if (!$this->notifications) {
            return;
        }

        try {
            new Address((string) $this->from);
        } catch (RfcComplianceException) {
            yield Finding::problem(\sprintf('Notifications come from "%s", which isn\'t an email address.', $this->from), 'Set notifications.from to an address, e.g. "security@example.com".', 'concepts/notifications.md#configuration');
        }

        $default = null === $this->transports ? null : array_first($this->transports);

        if (null === $default) {
            yield Finding::problem('Notifications are on, but the application has no Symfony Mailer to send them with.', 'Configure framework.mailer.dsn, or turn notifications off with notifications.enabled: false.', 'concepts/mail.md#delivering');
        } elseif (!$this->debug && (str_starts_with($default, 'null://') || 'smtp://null' === $default)) {
            // Symfony's default when no DSN is configured is "smtp://null".
            yield Finding::problem('Notifications go to a transport that drops them: nobody hears about new passkeys or devices.', 'Set framework.mailer.dsn (MAILER_DSN) to a transport that delivers.', 'concepts/mail.md#delivering');
        }
    }
}
