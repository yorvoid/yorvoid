<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where recovery codes are kept, as their SHA-256 hashes: a leaked database holds none that work.
 *
 * @api
 */
interface RecoveryCodeStore
{
    /**
     * Replaces all of the principal's codes with these.
     *
     * @param list<string> $hashes
     */
    public function replace(Realm $realm, PrincipalId $principalId, array $hashes, \DateTimeImmutable $createdAt): void;

    /**
     * Uses one of the principal's codes, removing it. Removing it is the check: of several requests
     * presenting it at once, one gets it.
     *
     * @return bool Whether the principal had it
     */
    public function use(Realm $realm, PrincipalId $principalId, string $hash): bool;

    /**
     * How many of its codes the principal has left.
     */
    public function count(Realm $realm, PrincipalId $principalId): int;
}
