<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;

/**
 * YorvoID's decisions until its evaluator (0.3): the host's operator from a console, job or system
 * origin; someone on their own account, as the {@see SelfServicePolicy} says; an ability as the
 * {@see AbilityPolicy} says; and authentication, which the handler checks. Anything else is Deny, and Indeterminate never permits.
 *
 * @api
 */
final readonly class BuiltInAuthorization implements Authorization
{
    private bool $commandLine;

    /**
     * @param bool|null $commandLine Whether PHP runs from the command line, as it does for console commands and
     *                               workers: the host's console and jobs act only from there. Found out if not given
     */
    public function __construct(
        private AbilityPolicy $abilities = new NoAbilityPolicy(),
        private SelfServicePolicy $selfService = new NoSelfService(),
        ?bool $commandLine = null,
    ) {
        $this->commandLine = $commandLine ?? \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        $refusal = Decision::deny(\sprintf('Nothing lets the actor run "%s".', $operation->name));

        foreach ($operation->authorizedBy as $by) {
            $decision = match ($by) {
                AuthorizedBy::Operator => $actor instanceof Operator && $this->fromTheHost($origin)
                    ? Decision::permit($by, 'The host\'s operator.')
                    : null,
                AuthorizedBy::Self => $command instanceof SelfOperation && self::inRealm($actor, $command) && $command->concerns($actor)
                    ? $this->selfService->decide($operation, $command, $actor)
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
     * The host's operator: a console command or a job, which run from the command line, or YorvoID itself
     * (OriginKind::System), e.g. a notification's token after a request's operation. A web request saying
     * it's the console isn't.
     */
    private function fromTheHost(Origin $origin): bool
    {
        return match ($origin->kind) {
            OriginKind::Console, OriginKind::Job => $this->commandLine,
            OriginKind::System => true,
            OriginKind::Web, OriginKind::Api => false,
        };
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
