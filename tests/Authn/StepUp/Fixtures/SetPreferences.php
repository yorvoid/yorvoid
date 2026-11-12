<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\StepUp\Fixtures;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * An application's command about a principal, not self-service: nothing is asked of one's own unless the
 * application says so.
 *
 * @implements Command<null>
 */
#[Operation('acme.preferences.set', 'Set a principal\'s preferences', AuthorizedBy::Ability, ability: 'acme.preferences.manage')]
final readonly class SetPreferences implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
