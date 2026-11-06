<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;

/**
 * YorvoID's decisions until its evaluator (0.3): the host's operator from a console, job or system
 * origin; someone on their own account; an ability as the {@see AbilityPolicy} says; and authentication,
 * which the handler checks. Anything else is Deny, and Indeterminate never permits.
 *
 * @api
 */
final readonly class BuiltInAuthorization implements Authorization
{
    private const array OPERATOR_ORIGINS = [OriginKind::Console, OriginKind::Job, OriginKind::System];

    public function __construct(
        private AbilityPolicy $abilities = new NoAbilityPolicy(),
    ) {
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        $refusal = Decision::deny(\sprintf('Nothing lets the actor run "%s".', $operation->name));

        foreach ($operation->authorizedBy as $by) {
            $decision = match ($by) {
                AuthorizedBy::Operator => $actor instanceof Operator && \in_array($origin->kind, self::OPERATOR_ORIGINS, true)
                    ? Decision::permit($by, 'The host\'s operator.')
                    : null,
                AuthorizedBy::Self => $command instanceof SelfOperation && self::inRealm($actor, $command) && $command->concerns($actor)
                    ? Decision::permit($by, 'The actor\'s own account.')
                    : null,
                AuthorizedBy::Ability => $this->ability($operation, $command, $actor),
                AuthorizedBy::Authentication => Decision::permit($by, 'Authentication: the handler checks it.'),
                AuthorizedBy::Consequence => null,
            };

            if (null === $decision) {
                continue;
            }

            if ($decision->permits()) {
                return $decision;
            }

            // Indeterminate outweighs Deny: it says a policy couldn't decide, which someone should see.
            if (Verdict::Deny === $refusal->verdict) {
                $refusal = $decision;
            }
        }

        return $refusal;
    }

    /**
     * @param Command<mixed> $command
     */
    private function ability(OperationDescription $operation, Command $command, Actor $actor): ?Decision
    {
        if (null === $operation->ability || !self::inRealm($actor, $command)) {
            return null;
        }

        return match ($this->abilities->holds($actor, $operation->ability, $command)) {
            true => Decision::permit(AuthorizedBy::Ability, \sprintf('The actor holds "%s".', $operation->ability)),
            false => Decision::deny(\sprintf('The actor doesn\'t hold "%s".', $operation->ability)),
            null => Decision::indeterminate(\sprintf('No policy says who holds "%s".', $operation->ability)),
        };
    }

    /**
     * @param Command<mixed> $command
     */
    private static function inRealm(Actor $actor, Command $command): bool
    {
        return null !== $actor->principalId && null !== $actor->realm && $actor->realm->equals($command->realm);
    }
}
