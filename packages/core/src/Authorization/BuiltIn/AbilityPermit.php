<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A principal of the command's realm holding the operation's ability, as the ability policy says: for
 * operations only, since an AbilityPolicy is asked with the command.
 *
 * @internal
 */
final readonly class AbilityPermit implements PermitElement
{
    public function __construct(
        private AbilityPolicy $policy,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.ability';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    public function matches(Request $request): bool
    {
        return null !== $request->command && null !== $request->ability && $request->actorInRealm();
    }

    public function evaluate(Request $request): Truth
    {
        $ability = $request->ability;
        $command = $request->command;
        \assert(null !== $ability && null !== $command);

        return match ($this->policy->holds($request->actor, $ability, $command)) {
            true => Truth::holds(\sprintf('The actor holds "%s".', $ability)),
            false => Truth::fails(\sprintf('The actor doesn\'t hold "%s".', $ability)),
            null => Truth::unknown(IndeterminateReason::MissingData, \sprintf('The ability policy can\'t tell who holds "%s".', $ability)),
        };
    }
}
