<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Where the verifier keeps its state (INT-007, INT-009): itself, surviving restarts.
 *
 * Use FileVerifierStore (InMemoryVerifierStore in tests). One of your own must pass yorvoid/testing's
 * VerifierStoreContract and VerifierStoreConcurrencyContract, and keep the state out of the console's
 * reach, as FileVerifierStore does (a regular file, not a link, the verifier's user's alone): the state
 * decides which keys sign. The contracts can't test that last part, so that one is yours to keep.
 *
 * @api
 *
 * @spi Implement it, don't call it: calling a store changes YorvoID's records without asking YorvoID
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
