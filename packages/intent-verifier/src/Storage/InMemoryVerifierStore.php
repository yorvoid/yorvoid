<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

use YorvoID\IntentVerifier\VerifierState;
use YorvoID\IntentVerifier\VerifierStore;

/**
 * The verifier's state in memory: for tests. Forgotten when the process ends.
 *
 * @api
 */
final class InMemoryVerifierStore implements VerifierStore
{
    private VerifierState $state;

    public function __construct()
    {
        $this->state = new VerifierState();
    }

    public function transaction(\Closure $work): mixed
    {
        $state = clone $this->state;
        $result = $work($state);
        $this->state = $state;

        return $result;
    }
}
