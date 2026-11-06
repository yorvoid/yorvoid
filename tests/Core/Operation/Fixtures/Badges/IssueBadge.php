<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * @implements SelfOperation<string>
 */
#[Operation('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Self, AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'acme.badges.issue', causes: ['acme.badge.record'])]
final readonly class IssueBadge implements SelfOperation
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return null !== $actor->principalId && $actor->principalId->equals($this->principalId);
    }
}
