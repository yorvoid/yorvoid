<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup\Storage;

use YorvoID\Authn\Setup\SetupState;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;

/**
 * @implements InMemoryStore<array<string, SetupState>>
 *
 * @api
 */
final class InMemorySetupStore implements SetupStore, InMemoryStore
{
    /** @var array<string, SetupState> By realm */
    private array $states = [];

    public function get(Realm $realm): ?SetupState
    {
        return $this->states[$realm->name] ?? null;
    }

    public function replaceCode(Realm $realm, string $codeHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): bool
    {
        if (true === $this->get($realm)?->isClaimed()) {
            return false;
        }

        $this->states[$realm->name] = new SetupState($realm, $codeHash, $createdAt, $expiresAt);

        return true;
    }

    public function claim(Realm $realm, string $codeHash, PrincipalId $by, \DateTimeImmutable $at): bool
    {
        $state = $this->get($realm);

        if (null === $state || $state->isClaimed() || $codeHash !== $state->codeHash) {
            return false;
        }

        $this->states[$realm->name] = new SetupState($realm, $codeHash, $state->createdAt, $state->expiresAt, $at, $by);

        return true;
    }

    public function snapshot(): mixed
    {
        return $this->states;
    }

    public function restore(mixed $snapshot): void
    {
        $this->states = $snapshot;
    }
}
