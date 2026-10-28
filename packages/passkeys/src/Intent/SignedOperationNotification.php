<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent;

use YorvoID\Authn\Notification\SecurityNotifications;
use YorvoID\Passkeys\Intent\Event\SignedOperationRan;

/**
 * Emails the principal after each operation they signed ran (INT-012), with its summary in their
 * language and "This wasn't me": register it as a listener of SignedOperationRan.
 *
 * @api
 */
final readonly class SignedOperationNotification
{
    public function __construct(
        private SecurityNotifications $notifications,
        private IntentSummaries $summaries,
    ) {
    }

    public function __invoke(SignedOperationRan $event): void
    {
        $this->notifications->signedOperation($event->realm, $event->principalId, fn (string $locale): string => $this->summaries->summarize($event->intent, $locale)->text, $event->at);
    }
}
