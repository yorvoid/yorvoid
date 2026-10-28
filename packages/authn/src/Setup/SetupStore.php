<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where each realm's setup state is kept: its current code's SHA-256 hash, or who claimed it.
 *
 * @api
 */
interface SetupStore
{
    public function get(Realm $realm): ?SetupState;

    /**
     * Replaces the realm's code, unless the realm was claimed.
     *
     * @return bool Whether it did
     */
    public function replaceCode(Realm $realm, string $codeHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): bool;

    /**
     * Marks the realm claimed, if it isn't yet and its code still has this hash: of two transactions
     * claiming it at once, one succeeds.
     *
     * @return bool Whether this call claimed it
     */
    public function claim(Realm $realm, string $codeHash, PrincipalId $by, \DateTimeImmutable $at): bool;
}
