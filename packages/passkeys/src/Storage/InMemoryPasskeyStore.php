<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Storage;

use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Passkeys\Passkey;
use YorvoID\Passkeys\PasskeyStore;

/**
 * @implements InMemoryStore<array<string, Passkey>>
 *
 * @api
 */
final class InMemoryPasskeyStore implements PasskeyStore, InMemoryStore
{
    /** @var array<string, Passkey> By credential ID */
    private array $passkeys = [];

    public function add(Passkey $passkey): bool
    {
        if (isset($this->passkeys[(string) $passkey->credentialId]) || null !== $this->find($passkey->realm, $passkey->webauthnId)) {
            return false;
        }

        $this->passkeys[(string) $passkey->credentialId] = $passkey;

        return true;
    }

    public function find(Realm $realm, string $webauthnId): ?Passkey
    {
        foreach ($this->passkeys as $passkey) {
            if ($passkey->realm->equals($realm) && $webauthnId === $passkey->webauthnId) {
                return $passkey;
            }
        }

        return null;
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        return array_values(array_filter($this->passkeys, static fn (Passkey $passkey): bool => $passkey->realm->equals($realm) && $passkey->principalId->equals($principalId)));
    }

    public function update(Passkey $passkey): void
    {
        $existing = $this->passkeys[(string) $passkey->credentialId] ?? null;

        if (null !== $existing && $existing->realm->equals($passkey->realm)) {
            $this->passkeys[(string) $passkey->credentialId] = $passkey;
        }
    }

    public function remove(Realm $realm, CredentialId $credentialId): void
    {
        if (true === ($this->passkeys[(string) $credentialId] ?? null)?->realm->equals($realm)) {
            unset($this->passkeys[(string) $credentialId]);
        }
    }

    public function snapshot(): mixed
    {
        return $this->passkeys;
    }

    public function restore(mixed $snapshot): void
    {
        $this->passkeys = $snapshot;
    }
}
