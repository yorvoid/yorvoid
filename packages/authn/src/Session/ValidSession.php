<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Principal\Principal;

/**
 * A session that passed Sessions::check(), with its principal as the check found it: active, and as it
 * was when the session began. Whoever needs the principal next, in the same request, needn't load it
 * again.
 *
 * @api
 */
final readonly class ValidSession
{
    public function __construct(
        public Session $session,
        public Principal $principal,
    ) {
    }
}
