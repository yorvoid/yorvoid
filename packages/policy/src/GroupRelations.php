<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\ResourceType;
use YorvoID\Relations\Subject;

/**
 * Groups as relations (IDN-056): group:<name>#member is a principal, or another group's members, so
 * groups nest. Give them to the relation schema with the application's own types.
 *
 * @api
 */
final readonly class GroupRelations implements RelationDeclarations
{
    public const string TYPE = 'group';

    public const string MEMBER = 'member';

    /**
     * A group's members, as a subject: to put one group within another.
     */
    public static function members(string $group): Subject
    {
        return new Subject(self::group($group), self::MEMBER);
    }

    public static function group(string $group): ResourceRef
    {
        return new ResourceRef(self::TYPE, $group);
    }

    public function types(): iterable
    {
        yield new ResourceType(self::TYPE, [new RelationDefinition(self::MEMBER)], reserved: true);
    }
}
