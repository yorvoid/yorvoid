<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Where the verifier keeps its state (INT-007, INT-009): itself, surviving restarts.
 *
 * @api
 */
interface VerifierStore
{
    /**
     * Runs the work on the state while no other process can: what it changes is saved when it returns,
     * and forgotten if it throws.
     *
     * @template T
     *
     * @param \Closure(VerifierState): T $work
     *
     * @return T
     */
    public function transaction(\Closure $work): mixed;
}
