<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Secret;

/**
 * A new session and its token. The token goes to the client once (in a cookie, or the application's PHP
 * session); YorvoID keeps only its hash, so it can't be shown again.
 *
 * @api
 */
final readonly class StartedSession
{
    /**
     * @param Secret|null $deviceCookie The device cookie to set, long-lived, when device recognition is on: the one
     *                                  sent, or a new one for a device the principal hasn't used before
     * @param bool        $newDevice    Whether the principal signed in from a device it hasn't used before, while
     *                                  it has used others
     */
    public function __construct(
        public Session $session,
        public Secret $token,
        public ?Secret $deviceCookie = null,
        public bool $newDevice = false,
    ) {
    }
}
