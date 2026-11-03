<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

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
     * @param bool                       $own        Whether the application sends YorvoID's emails its own way, not
     *                                               through Symfony Mailer: then its transports aren't YorvoID's
     */
    public function __construct(
        private bool $notifications,
        private ?string $from,
        private ?array $transports,
        private bool $debug,
        private bool $own = false,
    ) {
    }

    public function findings(): iterable
    {
        if (!$this->notifications) {
            if (!$this->debug) {
                yield Finding::warning('Notifications are off: nobody hears about recoveries, new passkeys or new devices, so a taken-over account goes unnoticed.', 'Turn them on with notifications.enabled: true, and configure Symfony Mailer.', 'concepts/notifications.md#configuration');
            }

            return;
        }

        // Without Symfony Mime, which an application's own mailer may not need.
        if (false === filter_var((string) $this->from, \FILTER_VALIDATE_EMAIL)) {
            yield Finding::problem(\sprintf('Notifications come from "%s", which isn\'t an email address.', $this->from), 'Set notifications.from to an address, e.g. "security@example.com".', 'concepts/notifications.md#configuration');
        }

        if ($this->own) {
            return;
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
