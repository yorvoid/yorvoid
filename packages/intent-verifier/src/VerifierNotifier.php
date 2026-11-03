<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Tells the owners what the verifier did (INT-012), through a channel the console doesn't control: e.g.
 * an email the privileged process sends itself. The verifier calls it after each change is saved: an
 * operation verified, a key added, removed or bootstrapped, a bootstrap secret created.
 *
 * Don't swallow failures: an exception propagates, and an operation whose notice couldn't be sent isn't
 * run (verify() throws). Queue the message rather than send it inline if delivery can be slow.
 *
 * @api
 */
interface VerifierNotifier
{
    public function notify(VerifierNotice $notice): void;
}
