<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * @api
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
     * Replaces the label, last use and status of a credential.
     */
    public function update(Credential $credential): void;

    /**
     * @return bool Whether it was there
     */
    public function remove(Realm $realm, CredentialId $id): bool;
}
