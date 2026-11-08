<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;

/**
 * A role given to a group (IDN-056): every member has it, members of groups within it too.
 *
 * @api
 */
final readonly class GroupRoleAssignment
{
    public function __construct(
        public Realm $realm,
        public string $group,
        public string $role,
        public \DateTimeImmutable $assignedAt,
        public ?ResourceRef $scope = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function counts(\DateTimeImmutable $at, ResourceRef ...$resources): bool
    {
        return RoleAssignment::applies($this->scope, $this->expiresAt, $at, $resources);
    }
}
