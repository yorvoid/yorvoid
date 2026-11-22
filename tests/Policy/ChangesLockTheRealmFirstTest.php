<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Policy\Attribute\SetAttribute;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RelabelRole;
use YorvoID\Policy\Change\RemoveGroupMember;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignGroupRole;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\StatementLog;

/**
 * Every change of roles, groups, abilities and attributes takes the realm's lock before any other: a
 * guard rule that reads them waits for it, or is waited for (D-52). On a database, so the suite's
 * lock-order check sees each change too.
 */
final class ChangesLockTheRealmFirstTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testEveryChangeLocksTheRealmFirst(string $platform): void
    {
        $statements = new StatementLog();
        $world = new PolicyWorld(stepUp: new StubStepUpCheck(), stack: $this->stack($platform, $statements));
        $realm = $world->realm;
        $ada = self::id($world->principal());
        $member = Subject::principal($ada);

        foreach ([
            new CreateRole($realm, 'reviewer', 'Reviewer'),
            new RelabelRole($realm, 'reviewer', 'Senior'),
            new GrantAbilities($realm, 'reviewer', ['acme.pages.publish']),
            new RevokeAbilities($realm, 'reviewer', ['acme.pages.publish']),
            new SetAbilities($realm, 'reviewer', ['acme.pages.view_drafts']),
            new ResetAbilities($realm, ['acme.pages.view_drafts']),
            new AssignRole($realm, $ada, 'reviewer'),
            new UnassignRole($realm, $ada, 'reviewer'),
            new CreateGroup($realm, 'editors', 'Editors'),
            new AddGroupMember($realm, 'editors', $member),
            new AssignGroupRole($realm, 'editors', 'reviewer'),
            new UnassignGroupRole($realm, 'editors', 'reviewer'),
            new RemoveGroupMember($realm, 'editors', $member),
            new DeleteGroup($realm, 'editors'),
            new DeleteRole($realm, 'reviewer'),
            new SetAttribute($realm, $ada, 'acme.department', 'research'),
        ] as $command) {
            $statements->take();
            $world->asOperator($command);

            self::assertStringContainsString('realm_lock', $statements->takeLocks()[0] ?? '', $command::class.' didn\'t lock the realm first.');
        }
    }

    private static function id(Actor $actor): PrincipalId
    {
        \assert(null !== $actor->principalId);

        return $actor->principalId;
    }
}
