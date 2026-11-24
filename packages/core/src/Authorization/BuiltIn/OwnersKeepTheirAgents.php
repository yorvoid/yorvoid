<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\PrincipalStore;

/**
 * A principal that owns agents isn't deleted (AGT-001): a refusal, owns_agents, decided before anything
 * is written, whoever asks, the owner for their own account too. Move the agents to another owner
 * (ChangeAgentOwner), or delete them, first. Every evaluator whose authority check is PrincipalAuthority
 * has it.
 *
 * @internal
 */
final readonly class OwnersKeepTheirAgents implements ForbidElement
{
    public const string NAME = 'yorvoid.principal.owns_agents';

    public function __construct(
        private PrincipalStore $principals,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function matches(Request $request): bool
    {
        return $request->command instanceof DeletePrincipal;
    }

    public function evaluate(Request $request): Truth
    {
        \assert($request->command instanceof DeletePrincipal);

        return $this->principals->ownsAgents($request->command->realm, $request->command->principalId)
            ? Truth::holds('The principal owns agents: move them to another owner, or delete them, first.')->grounded(Ground::authority('owns_agents'))
            : Truth::fails('The principal owns no agent.');
    }
}
