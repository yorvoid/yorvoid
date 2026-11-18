<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A one-time token store that loses races (LosingStoreTest): it says a token was deleted if it found it,
 * whether or not its own delete took it.
 */
final readonly class CheckThenDeleteTokenStore implements OneTimeTokenStore
{
    public function __construct(
        private OneTimeTokenStore $store,
    ) {
    }

    public function add(OneTimeToken $token, string $verifierHash): void
    {
        $this->store->add($token, $verifierHash);
    }

    public function find(Realm $realm, string $selector, string $purpose): ?array
    {
        return $this->store->find($realm, $selector, $purpose);
    }

    public function delete(Realm $realm, string $selector): bool
    {
        $this->store->delete($realm, $selector);

        return true;
    }

    public function deleteFor(Realm $realm, PrincipalId $principalId, string $purpose): int
    {
        return $this->store->deleteFor($realm, $principalId, $purpose);
    }

    public function purge(\DateTimeImmutable $now): int
    {
        return $this->store->purge($now);
    }
}
