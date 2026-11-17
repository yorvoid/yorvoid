<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Finishes a step-up with a passkey (ATN-072), with the browser's answer to the ceremony that
 * Passkeys::beginStepUp() began for the session: run from that session, and the handler checks the
 * answer. The proof is for the operation the ceremony was begun for, whatever the answer says.
 *
 * @implements Command<Secret>
 *
 * @api
 */
// yorvoid.credential.disable twice: now, and again after the caller's rollback, until ARC-0059 lets one
// afterEnd() do it (D-42).
#[Operation('yorvoid.passkey.step_up', 'Step up a session with a passkey', AuthorizedBy::Authentication, causes: ['yorvoid.credential.disable', 'yorvoid.credential.disable', 'yorvoid.session.step_up'])]
final readonly class StepUpWithPasskey implements Command
{
    /**
     * @param string $response The browser's PublicKeyCredential, as JSON
     */
    public function __construct(
        public Realm $realm,
        public string $ceremonyId,
        public string $response,
    ) {
    }
}
