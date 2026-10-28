<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Realm;

/**
 * @api
 */
interface CeremonyStore
{
    public function add(Ceremony $ceremony): void;

    /**
     * Removes the ceremony and returns it: of two requests finishing it at once, one gets it.
     */
    public function take(Realm $realm, Uuid $id): ?Ceremony;

    /**
     * Removes the ceremonies that expired at or before the moment, in every realm.
     *
     * @return int How many
     */
    public function purge(\DateTimeImmutable $now): int;
}
