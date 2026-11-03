<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Keeps the notices in memory: for tests, or for a host that sends them after the call, e.g. at the end
 * of its request.
 *
 * @api
 */
final class CollectingNotifier implements VerifierNotifier
{
    /** @var list<VerifierNotice> */
    public array $notices = [];

    public function notify(VerifierNotice $notice): void
    {
        $this->notices[] = $notice;
    }
}
