<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use YorvoID\Authn\Session\Session;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;

/**
 * Abilities as the application's Symfony security says, until YorvoID's evaluator decides them: an
 * actor holds an ability when it's the request's own session and `isGranted($ability, $command)` says
 * so, so the application's voters and role hierarchy decide. For any other actor it can't tell, and
 * the operation doesn't run.
 *
 * @internal The bundle registers it
 */
final readonly class SymfonyAbilityPolicy implements AbilityPolicy
{
    /**
     * @param \Closure(): ?Session $current The request's session, asked for lazily: CurrentSession runs operations itself
     */
    public function __construct(
        private AuthorizationCheckerInterface $checker,
        private \Closure $current,
    ) {
    }

    public function holds(Actor $actor, string $ability, Command $command): ?bool
    {
        $session = ($this->current)();

        if (!$actor instanceof Session || null === $session || !$session->id->equals($actor->id)) {
            return null;
        }

        return $this->checker->isGranted($ability, $command);
    }
}
