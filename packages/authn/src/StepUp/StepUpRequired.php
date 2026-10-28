<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * The session hasn't proved who it is recently enough, or strongly enough, for the operation (ATN-070):
 * a session taken over hours ago mustn't be able to add the attacker's passkey. The requirement is the
 * remedy (D-07): ask the person to confirm it's them, e.g. with Passkeys::beginStepUp() for its
 * operation, then retry.
 *
 * @api
 */
final class StepUpRequired extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly AuthenticationRequirement $requirement,
    ) {
        parent::__construct('Confirm it\'s you, then try once more.');
    }
}
