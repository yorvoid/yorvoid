<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Operation\SelfServicePolicy;

/**
 * Someone changing their own account shows what the self-service policy asks of it, whatever permits the
 * change, e.g. their role: a manager who recovered an account doesn't move its email address during the
 * hold (M2-13, H-4). Every evaluator has it. The operator, the final authority, has no account of its own.
 *
 * @internal
 */
final readonly class SelfServiceForOwnAccount implements ForbidElement
{
    public const string NAME = 'yorvoid.self_service.own_account';

    public function __construct(
        public SelfServicePolicy $policy,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function matches(Request $request): bool
    {
        $command = $request->command;
        $principal = $request->actor->principalId;

        // An ability's request has no command; the operator has no principal and no realm, so no account.
        if (null === $command || !$request->actorInRealm()) {
            return false;
        }

        return ($command instanceof SelfOperation && $command->concerns($request->actor))
            || ($command instanceof ConcernsPrincipal && null !== $principal && $command->principal()->equals($principal));
    }

    public function evaluate(Request $request): Truth
    {
        \assert(null !== $request->command && null !== $request->operation);
        $asked = $this->policy->decide($request->operation, $request->command, $request->actor);

        return match ($asked->value) {
            TruthValue::Holds => Truth::fails($asked->detail)->grounded(...$asked->grounds),
            TruthValue::Fails => Truth::holdsUnless(\sprintf('On one\'s own account: %s', lcfirst($asked->detail)), ...$asked->remedies),
            TruthValue::Unknown => $asked,
        };
    }
}
