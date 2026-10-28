<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;

/**
 * Notes, rather than refuses, a signature counter that didn't go up, so that the clone policy decides.
 *
 * @internal
 */
final class CounterSuspicion implements CounterChecker
{
    public bool $suspected = false;

    /**
     * The library calls this only when a counter isn't 0: synced passkeys report 0 every time. Any other
     * counter must go up.
     */
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        $this->suspected = $currentCounter <= $credentialRecord->counter;
    }
}
