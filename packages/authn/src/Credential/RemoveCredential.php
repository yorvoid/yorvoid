<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Removes a credential from the signed-in principal's own account (ATN-005).
 *
 * @api
 */
#[Operation('yorvoid.credential.remove', 'Remove a sign-in method from one\'s account')]
final readonly class RemoveCredential
{
    /**
     * @param bool $endOtherSessions Whether the principal's other sessions end too (SES-006), e.g. because the
     *                               passkey was on a lost phone
     */
    public function __construct(
        public Realm $realm,
        public CredentialId $credentialId,
        public bool $endOtherSessions = false,
    ) {
    }
}
