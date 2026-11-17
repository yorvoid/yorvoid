<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * @api
 *
 * @spi Implement it, don't call it: calling a store changes YorvoID's records without asking YorvoID
 */
interface CredentialStore
{
    public function add(Credential $credential): void;

    public function get(Realm $realm, CredentialId $id): ?Credential;

    /**
     * A principal's credentials, the oldest first.
     *
     * @return list<Credential>
     */
    public function of(Realm $realm, PrincipalId $principalId): array;

    /**
     * Changes a credential's label, and nothing else.
     *
     * @return bool Whether it was there
     */
    public function rename(Realm $realm, CredentialId $id, string $label): bool;

    /**
     * Records when an active credential was last used, in one statement that checks it's active: a
     * credential disabled since the caller read it stays disabled, and isn't marked.
     *
     * @return bool Whether it was there and active
     */
    public function markUsed(Realm $realm, CredentialId $id, \DateTimeImmutable $at): bool;

    /**
     * Makes a credential disabled, and changes nothing else.
     *
     * @return bool Whether it was there
     */
    public function disable(Realm $realm, CredentialId $id): bool;

    /**
     * @return bool Whether it was there
     */
    public function remove(Realm $realm, CredentialId $id): bool;
}
