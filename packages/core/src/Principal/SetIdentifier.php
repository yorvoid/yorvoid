<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * Sets a principal's email address or username, replacing the one it had of that kind.
 *
 * @implements SelfOperation<Identifier>
 *
 * @api
 */
#[Operation('yorvoid.identifier.set', 'Set an email address or username of a principal', [AuthorizedBy::Self, AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.identifier.remove', causes: ['yorvoid.token.issue'])]
final readonly class SetIdentifier implements SelfOperation
{
    /**
     * @param bool $verified Whether its owner has shown it's theirs; only the code that saw them do so
     *                       says true. Setting a different value than before is unverified otherwise
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public IdentifierKind $kind,
        public string $value,
        public bool $verified = false,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        // Only the code that saw the owner show it's theirs marks it verified: never the owner's own say-so.
        return !$this->verified && null !== $actor->principalId && $actor->principalId->equals($this->principalId);
    }
}
