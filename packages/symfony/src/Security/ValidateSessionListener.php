<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Signs the person out the moment their YorvoID session isn't valid any more (SES-005): ended
 * elsewhere, expired, or their principal suspended. It runs on every main request before the firewall,
 * and empties the PHP session the firewall would sign them in from, so it works whatever user provider
 * the application uses.
 *
 * @internal
 */
final readonly class ValidateSessionListener
{
    public function __construct(
        private CurrentSession $current,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->current->isHeld() || null !== $this->current->get()) {
            return;
        }

        $event->getRequest()->getSession()->invalidate();
    }
}
