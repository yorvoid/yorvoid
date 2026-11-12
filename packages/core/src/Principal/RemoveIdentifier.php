<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * @implements SelfOperation<null>
 *
 * @api
 */
#[Operation('yorvoid.identifier.remove', 'Remove an email address or username of a principal', [AuthorizedBy::Self, AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.identifier.set')]
final readonly class RemoveIdentifier implements SelfOperation, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public IdentifierKind $kind,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return null !== $actor->principalId && $actor->principalId->equals($this->principalId);
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
