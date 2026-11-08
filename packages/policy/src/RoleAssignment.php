<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A role given to a principal (AUZ-005, 010): in the whole realm, or on one resource, e.g. the manager of
 * site 42, and then on what's within it (its children through "parent"); until it expires, if it does.
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
     * Whether it counts at a moment for these resources, e.g. a request's and its ancestors: not expired,
     * and in the whole realm or on one of them.
     */
    public function counts(\DateTimeImmutable $at, ResourceRef ...$resources): bool
    {
        return self::applies($this->scope, $this->expiresAt, $at, $resources);
    }

    /**
     * @param array<array-key, ResourceRef> $resources
     *
     * @internal
     */
    public static function applies(?ResourceRef $scope, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $at, array $resources): bool
    {
        if (null !== $expiresAt && $expiresAt <= $at) {
            return false;
        }

        if (null === $scope) {
            return true;
        }

        foreach ($resources as $resource) {
            if ($scope->equals($resource)) {
                return true;
            }
        }

        return false;
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
