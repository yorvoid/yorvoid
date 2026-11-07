<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * The permit by roles: a request that needs an ability is let through when a role the actor has, in the
 * realm or on the request's resource, has that ability, or is a super role and the ability isn't strict.
 *
 * @api
 */
final readonly class RolePermit implements PermitElement
{
    public function __construct(
        private Roles $roles,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.roles';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    public function matches(Request $request): bool
    {
        return null !== $request->ability;
    }

    public function evaluate(Request $request): Truth
    {
        return $this->roles->decide($request);
    }
}
