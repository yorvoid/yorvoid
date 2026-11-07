<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Operation\SelfServicePolicy;

/**
 * Someone acting on their own account, in its realm, with what the self-service policy asks for.
 *
 * @internal
 */
final readonly class SelfServicePermit implements PermitElement
{
    public function __construct(
        private SelfServicePolicy $policy,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.self_service';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Self;
    }

    public function matches(Request $request): bool
    {
        return $request->command instanceof SelfOperation && $request->actorInRealm() && $request->command->concerns($request->actor);
    }

    public function evaluate(Request $request): Truth
    {
        \assert($request->command instanceof SelfOperation);

        return $this->policy->decide($request->operation, $request->command, $request->actor);
    }
}
