<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Sets a principal's security attribute, or removes it (null).
 *
 * @implements Command<null>
 *
 * @api
 */
#[Operation('yorvoid.attribute.set', 'Set a security attribute of a principal', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::PRINCIPALS_MANAGE)]
final readonly class SetAttribute implements Command
{
    /**
     * @param string|int|bool|list<string>|null $value Of the attribute's type; null removes it
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $name,
        public string|int|bool|array|null $value,
    ) {
    }
}
