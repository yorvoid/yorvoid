<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\Actor;

/**
 * Proves an actor before a decision reads it (D-45, AR-05): the actor as it's recorded, e.g. the session
 * read from its store, or why it isn't one. yorvoid/authn proves sessions (StoredSessions); an actor
 * class of your own needs a prover of your own, or its decisions are Indeterminate (unproven_actor).
 *
 * @spi
 */
interface ProvesActors
{
    /** Whether it proves actors like this one */
    public function proves(Actor $actor): bool;

    /**
     * @return Actor|Truth The actor as recorded, which the decision then reads; or a Truth that fails
     *                     (a known invalid actor: Deny) or is unknown (Indeterminate)
     */
    public function prove(Actor $actor): Actor|Truth;
}
