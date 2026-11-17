<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use Symfony\Component\Uid\Uuid;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Realm;

/**
 * @api
 *
 * @spi Implement it, don't call it: calling a store changes YorvoID's records without asking YorvoID
 */
interface CeremonyStore
{
    public function add(Ceremony $ceremony): void;

    /**
     * Removes the ceremony and returns it: of two requests finishing it at once, one gets it. Only the
     * session that began a ceremony takes it, and a ceremony begun outside a session is taken only
     * without one: anyone else's attempt leaves it to its owner.
     */
    public function take(Realm $realm, Uuid $id, ?SessionId $session = null): ?Ceremony;

    /**
     * Removes the ceremonies that expired at or before the moment, in every realm.
     *
     * @return int How many
     */
    public function purge(\DateTimeImmutable $now): int;
}
