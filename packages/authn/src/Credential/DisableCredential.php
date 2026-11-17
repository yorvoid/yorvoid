<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Makes a credential stop working, e.g. a passkey suspected of having been copied; its owner sees it
 * as disabled, and removes it. Caused by what found it out: a passkey sign-in or step-up whose counter
 * went back (SignInWithPasskey, StepUpWithPasskey), or its owner saying they didn't add it (ReportNotMe).
 *
 * @implements Command<Credential|null>
 *
 * @api
 */
#[Operation('yorvoid.credential.disable', 'Disable a credential', AuthorizedBy::Consequence)]
final readonly class DisableCredential implements Command, ConcernsPrincipal
{
    /**
     * @param PrincipalId $principalId Whose it is: a credential of anyone else isn't disabled
     * @param string      $reason      Why, for the log, e.g. "signature counter went backwards"
     */
    public function __construct(
        public Realm $realm,
        public CredentialId $credentialId,
        public PrincipalId $principalId,
        public string $reason,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
