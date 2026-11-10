<?php

declare(strict_types=1);

namespace YorvoID\E2e\Acme;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Acme Team's own operation: removing one of its projects. It needs a recent proof of who one is
 * (RecentProofToRemove), so a session signed in a while ago is refused with a step-up remedy.
 *
 * @implements Command<void>
 */
#[Operation('acme.project.remove', 'Remove a project', AuthorizedBy::Ability, ability: 'acme.projects.remove')]
final readonly class RemoveProject implements Command
{
    public function __construct(
        public Realm $realm,
        public string $project,
    ) {
    }
}
