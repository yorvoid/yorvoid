<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Http\Event\LogoutEvent;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;

/**
 * Signing out through the firewall's logout ends the YorvoID session too (SES-004): emptying the PHP
 * session alone would leave a session that works for whoever kept its token. When the firewall keeps
 * the PHP session, every firewall's token leaves it too, under a new ID.
 *
 * @internal
 */
final readonly class EndSessionOnSignOut
{
    public function __construct(
        private CurrentSession $current,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $this->current->signOutKeepingData(new Origin(OriginKind::Web));
    }
}
