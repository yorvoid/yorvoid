<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential\Storage;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Core\Storage\StorageException;

/**
 * @implements InMemoryStore<array<string, Credential>>
 *
 * @api
 */
final class InMemoryCredentialStore implements CredentialStore, InMemoryStore
{
    /** @var array<string, Credential> By ID */
    private array $credentials = [];

    public function add(Credential $credential): void
    {
        if (isset($this->credentials[(string) $credential->id])) {
            throw new StorageException(\sprintf('A credential with the ID %s exists.', $credential->id));
        }

        $this->credentials[(string) $credential->id] = $credential;
    }

    public function get(Realm $realm, CredentialId $id): ?Credential
    {
        $credential = $this->credentials[(string) $id] ?? null;

        return null !== $credential && $credential->realm->equals($realm) ? $credential : null;
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $credentials = array_filter($this->credentials, static fn (Credential $credential): bool => $credential->realm->equals($realm) && $credential->principalId->equals($principalId));
        usort($credentials, static fn (Credential $a, Credential $b): int => [$a->createdAt, $a->id->uuid->toRfc4122()] <=> [$b->createdAt, $b->id->uuid->toRfc4122()]);

        return $credentials;
    }

    public function update(Credential $credential): void
    {
        if (null !== $this->get($credential->realm, $credential->id)) {
            $this->credentials[(string) $credential->id] = $credential;
        }
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        if (null === $this->get($realm, $id)) {
            return false;
        }

        unset($this->credentials[(string) $id]);

        return true;
    }

    public function snapshot(): mixed
    {
        return $this->credentials;
    }

    public function restore(mixed $snapshot): void
    {
        $this->credentials = $snapshot;
    }
}
