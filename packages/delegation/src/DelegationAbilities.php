<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

/**
 * The abilities of this package's operations, by name with what people read: the integration declares
 * them when the package is installed (the Symfony bundle does; in plain PHP, a PolicyDeclarations of
 * yours, as docs/concepts/delegation.md shows). Neither is delegable to an agent.
 *
 * @api
 *
 * @experimental
 */
final class DelegationAbilities
{
    /** Delegating one's own authority, or passing a delegation on: everyone has it by default */
    public const string ISSUE = 'yorvoid.delegations.issue';

    /** Revoking a delegation one issued, or one below it in its chain: everyone has it by default */
    public const string REVOKE = 'yorvoid.delegations.revoke';

    /** Revoking anyone's delegations, e.g. all of a principal's: strict, nobody's by default */
    public const string MANAGE = 'yorvoid.delegations.manage';

    /** @var array<string, string> By name, its label */
    public const array NAMES = [
        self::ISSUE => 'Delegate your authority',
        self::REVOKE => 'Revoke the delegations you issued',
        self::MANAGE => 'Revoke anyone\'s delegations',
    ];

    private function __construct()
    {
    }
}
