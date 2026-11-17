<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Authn\Credential\Credential;

/**
 * The set of codes was disabled after the check read it, e.g. by a "not me" report: thrown inside the
 * check's transaction so the code's use rolls back, and caught by RecoveryCodes, which records the
 * refusal.
 *
 * @internal
 */
final class CredentialDisabledMeanwhile extends \RuntimeException
{
    public function __construct(
        public readonly Credential $credential,
    ) {
        parent::__construct();
    }
}
