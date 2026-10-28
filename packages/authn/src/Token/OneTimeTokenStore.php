<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where one-time tokens are kept: each with the SHA-256 hash of its verifier, never the verifier.
 *
 * @api
 */
interface OneTimeTokenStore
{
    public function add(OneTimeToken $token, string $verifierHash): void;

    /**
     * @return array{OneTimeToken, string}|null The token and its verifier's hash
     */
    public function find(Realm $realm, string $selector, string $purpose): ?array;

    /**
     * Deletes the token, if it's still there: of two transactions deleting it at once, one succeeds.
     * This is what makes a token work only once.
     *
     * @return bool Whether this call deleted it
     */
    public function delete(Realm $realm, string $selector): bool;

    /**
     * @return int How many of the principal's tokens for the purpose were deleted
     */
    public function deleteFor(Realm $realm, PrincipalId $principalId, string $purpose): int;

    /**
     * Deletes the tokens that expired at or before the moment, in every realm.
     *
     * @return int How many
     */
    public function purge(\DateTimeImmutable $now): int;
}
