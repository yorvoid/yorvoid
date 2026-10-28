<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Realm;

/**
 * Where principals' identifiers are kept: at most one of each kind per principal, and each unique within
 * its realm in its normalized form.
 *
 * @api
 */
interface IdentifierStore
{
    /**
     * Adds the identifier, unless another one in the realm has the same kind and normalized form.
     * Replacing a principal's identifier is a remove() first, in the same transaction.
     *
     * @return bool Whether it was added; false if it's taken
     */
    public function add(Identifier $identifier): bool;

    public function remove(Realm $realm, PrincipalId $principalId, IdentifierKind $kind): void;

    public function removeAll(Realm $realm, PrincipalId $principalId): void;

    /**
     * The identifier of this kind with this normalized form.
     */
    public function find(Realm $realm, IdentifierKind $kind, string $normalized): ?Identifier;

    /**
     * A principal's identifiers, by kind.
     *
     * @return array<value-of<IdentifierKind>, Identifier>
     */
    public function of(Realm $realm, PrincipalId $principalId): array;
}
