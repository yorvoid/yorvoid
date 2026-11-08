<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Relations\RelationChanges;

/**
 * YorvoID's own abilities, which its operations need. It declares no roles: the application's decide who
 * has them. Issuing a recovery link hands an account over, so it's strict: a super role has it only when
 * it's granted.
 *
 * @api
 */
final readonly class YorvoIDDeclarations implements PolicyDeclarations
{
    public const string PRINCIPALS_MANAGE = 'yorvoid.principals.manage';

    public const string PRINCIPALS_RECOVER = 'yorvoid.principals.recover';

    public const string SESSIONS_MANAGE = 'yorvoid.sessions.manage';

    public const string ROLES_MANAGE = 'yorvoid.roles.manage';

    public const string PERMISSIONS_MANAGE = 'yorvoid.permissions.manage';

    public const string RELATIONS_MANAGE = RelationChanges::ABILITY;

    public const string POLICY_EXPLAIN = 'yorvoid.policy.explain';

    public function abilities(): iterable
    {
        yield new Ability(self::PRINCIPALS_MANAGE, 'Create, suspend and delete accounts, and change their identifiers', 'yorvoid', group: 'yorvoid');
        yield new Ability(self::PRINCIPALS_RECOVER, 'Issue a recovery link for someone\'s account', 'yorvoid', group: 'yorvoid', strict: true);
        yield new Ability(self::SESSIONS_MANAGE, 'Sign other people out', 'yorvoid', group: 'yorvoid');
        yield new Ability(self::ROLES_MANAGE, 'Create roles and give them to people', 'yorvoid', group: 'yorvoid');
        yield new Ability(self::PERMISSIONS_MANAGE, 'Change which roles have which abilities', 'yorvoid', group: 'yorvoid');
        yield new Ability(self::RELATIONS_MANAGE, 'Relate people and resources, e.g. make someone a page\'s editor', 'yorvoid', group: 'yorvoid');
        yield new Ability(self::POLICY_EXPLAIN, 'See why someone else may or may not do something', 'yorvoid', group: 'yorvoid', recordsPermits: true);
    }

    public function roles(): iterable
    {
        return [];
    }
}
