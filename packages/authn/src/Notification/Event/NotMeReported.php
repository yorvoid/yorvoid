<?php

declare(strict_types=1);

namespace YorvoID\Authn\Notification\Event;

use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal said a security notification wasn't them, and it committed: their sessions ended, and the
 * sign-in method it was about, if any, is disabled. Lead them to recovery.
 *
 * @api
 */
final readonly class NotMeReported
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public ?CredentialId $disabled,
        public Origin $origin,
    ) {
    }
}
