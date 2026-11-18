<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Core\Authorization\ProvesActors;
use YorvoID\Core\Operation\Actor;

/**
 * Takes a session, or a test's own actor, at its word: for tests of what a decision does with an actor,
 * not of whether it's on record, which StoredSessionsTest covers.
 */
final readonly class TrustedActors implements ProvesActors
{
    public function proves(Actor $actor): bool
    {
        return true;
    }

    public function prove(Actor $actor): Actor
    {
        return $actor;
    }
}
