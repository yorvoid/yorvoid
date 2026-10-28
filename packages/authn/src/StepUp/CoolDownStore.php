<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where the cool-downs after account recoveries are kept: one per principal, until a moment.
 *
 * @api
 */
interface CoolDownStore
{
    /**
     * Sets the principal's cool-down to end at that moment, unless it already ends later.
     */
    public function extend(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $until): void;

    /**
     * When the principal's cool-down ends, ended or not; null if there's none.
     */
    public function until(Realm $realm, PrincipalId $principalId): ?\DateTimeImmutable;

    /**
     * Removes the cool-downs that ended before the moment, in every realm.
     *
     * @return int How many
     */
    public function purge(\DateTimeImmutable $before): int;
}
