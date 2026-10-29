<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Http\Event\LogoutEvent;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;

/**
 * Signing out through the firewall's logout ends the YorvoID session too (SES-004): emptying the PHP
 * session alone would leave a session that works for whoever kept its token.
 *
 * @internal
 */
final readonly class EndSessionOnSignOut
{
    public function __construct(
        private CurrentSession $current,
        private Sessions $sessions,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $session = $this->current->get();

        if (null !== $session) {
            $this->sessions->end(new EndSession($session->realm, $session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        }

        $this->current->forget();
    }
}
