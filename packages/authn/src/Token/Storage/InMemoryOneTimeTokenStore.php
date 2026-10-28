<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token\Storage;

use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Core\Storage\StorageException;

/**
 * @implements InMemoryStore<array<string, array{OneTimeToken, string}>>
 *
 * @api
 */
final class InMemoryOneTimeTokenStore implements OneTimeTokenStore, InMemoryStore
{
    /** @var array<string, array{OneTimeToken, string}> By selector: the token and its verifier's hash */
    private array $tokens = [];

    public function add(OneTimeToken $token, string $verifierHash): void
    {
        if (isset($this->tokens[$token->selector])) {
            throw new StorageException(\sprintf('A token with the selector %s exists.', $token->selector));
        }

        $this->tokens[$token->selector] = [$token, $verifierHash];
    }

    public function find(Realm $realm, string $selector, string $purpose): ?array
    {
        $found = $this->tokens[$selector] ?? null;

        return null !== $found && $found[0]->realm->equals($realm) && $purpose === $found[0]->purpose ? $found : null;
    }

    public function delete(Realm $realm, string $selector): bool
    {
        if (!isset($this->tokens[$selector]) || !$this->tokens[$selector][0]->realm->equals($realm)) {
            return false;
        }

        unset($this->tokens[$selector]);

        return true;
    }

    public function deleteFor(Realm $realm, PrincipalId $principalId, string $purpose): int
    {
        $count = \count($this->tokens);
        $this->tokens = array_filter($this->tokens, static fn (array $entry): bool => !($entry[0]->realm->equals($realm) && $entry[0]->principalId->equals($principalId) && $purpose === $entry[0]->purpose));

        return $count - \count($this->tokens);
    }

    public function purge(\DateTimeImmutable $now): int
    {
        $count = \count($this->tokens);
        $this->tokens = array_filter($this->tokens, static fn (array $entry): bool => $entry[0]->expiresAt > $now);

        return $count - \count($this->tokens);
    }

    public function snapshot(): mixed
    {
        return $this->tokens;
    }

    public function restore(mixed $snapshot): void
    {
        $this->tokens = $snapshot;
    }
}
