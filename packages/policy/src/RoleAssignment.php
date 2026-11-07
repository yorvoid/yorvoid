<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A role given to a principal (AUZ-005, 010): in the whole realm, or on one resource, e.g. the manager of
 * site 42; until it expires, if it does.
 *
 * @api
 */
final readonly class RoleAssignment
{
    /**
     * @param ResourceRef|null        $scope     The resource it's on; null for the whole realm
     * @param \DateTimeImmutable|null $expiresAt When it stops counting; null for never
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $role,
        public \DateTimeImmutable $assignedAt,
        public ?ResourceRef $scope = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    /**
     * Whether it counts for a request at a moment about a resource: not expired, and in the whole realm
     * or on that resource.
     */
    public function counts(\DateTimeImmutable $at, ?ResourceRef $resource): bool
    {
        if (null !== $this->expiresAt && $this->expiresAt <= $at) {
            return false;
        }

        return null === $this->scope || (null !== $resource && $this->scope->equals($resource));
    }

    /**
     * Whether it's the same assignment: one principal, one role, one scope.
     */
    public function sameAs(self $other): bool
    {
        return $this->realm->equals($other->realm)
            && $this->principalId->equals($other->principalId)
            && $this->role === $other->role
            && (null === $this->scope ? null === $other->scope : null !== $other->scope && $this->scope->equals($other->scope));
    }
}
