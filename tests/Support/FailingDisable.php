<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A credential store whose disable fails, as when the database goes away: everything else is the store's.
 */
final readonly class FailingDisable implements CredentialStore
{
    public function __construct(private CredentialStore $store)
    {
    }

    public function add(Credential $credential): void
    {
        $this->store->add($credential);
    }

    public function get(Realm $realm, CredentialId $id): ?Credential
    {
        return $this->store->get($realm, $id);
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        return $this->store->of($realm, $principalId);
    }

    public function rename(Realm $realm, CredentialId $id, string $label): bool
    {
        return $this->store->rename($realm, $id, $label);
    }

    public function markUsed(Realm $realm, CredentialId $id, \DateTimeImmutable $at): bool
    {
        return $this->store->markUsed($realm, $id, $at);
    }

    public function disable(Realm $realm, CredentialId $id): bool
    {
        throw new \RuntimeException('The database went away.');
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        return $this->store->remove($realm, $id);
    }
}
