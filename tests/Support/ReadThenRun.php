<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A credential store that runs something once, once armed, right after the next read: of a principal's
 * credentials ("of"), or of one ("get").
 */
final class ReadThenRun implements CredentialStore
{
    public bool $ran = false;

    private ?\Closure $then = null;

    public function __construct(
        private readonly CredentialStore $store,
        private readonly string $after = 'of',
    ) {
    }

    public function arm(\Closure $then): void
    {
        $this->then = $then;
    }

    public function add(Credential $credential): void
    {
        $this->store->add($credential);
    }

    public function get(Realm $realm, CredentialId $id): ?Credential
    {
        $credential = $this->store->get($realm, $id);
        $this->ran('get');

        return $credential;
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $credentials = $this->store->of($realm, $principalId);
        $this->ran('of');

        return $credentials;
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
        return $this->store->disable($realm, $id);
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        return $this->store->remove($realm, $id);
    }

    private function ran(string $read): void
    {
        if ($read !== $this->after || null === $this->then || $this->ran) {
            return;
        }

        $this->ran = true;
        ($this->then)();
    }
}
