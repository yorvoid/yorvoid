<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RelabelRole;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Policy\UnknownRole;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Policy\Fixtures\StubStepUpCheck;

/**
 * Each change of the policy is one transaction that increments the realm's version and is recorded in
 * the security log; giving a strict ability or a super role takes a recent proof (AUZ-052).
 */
final class PolicyChangesTest extends TestCase
{
    public function testEachChangeIsRecordedAndIncrementsTheVersion(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();
        $id = self::id($ada)->uuid->toRfc4122();
        $start = $world->memory->policy->version($world->realm);
        $until = new \DateTimeImmutable('2026-12-01 10:00:00.123456 UTC');

        $world->asOperator(new CreateRole($world->realm, 'reviewer', 'Reviewer', from: 'member'));
        $world->asOperator(new RelabelRole($world->realm, 'reviewer', 'Senior'));
        $assignment = $world->asOperator(new AssignRole($world->realm, self::id($ada), 'reviewer', new ResourceRef('site', '1'), $until));
        self::assertTrue($world->asOperator(new UnassignRole($world->realm, self::id($ada), 'reviewer', new ResourceRef('site', '1'))));
        self::assertFalse($world->asOperator(new UnassignRole($world->realm, self::id($ada), 'reviewer')), 'There\'s none in the whole realm.');
        $world->asOperator(new GrantAbilities($world->realm, 'reviewer', ['acme.pages.publish', 'acme.pages.publish']));
        $world->asOperator(new RevokeAbilities($world->realm, 'reviewer', ['acme.pages.publish']));
        $world->asOperator(new SetAbilities($world->realm, 'reviewer', ['acme.pages.view_drafts']));
        $world->asOperator(new ResetAbilities($world->realm, ['acme.pages.view_drafts']));
        $world->asOperator(new ResetAbilities($world->realm));
        $world->asOperator(new DeleteRole($world->realm, 'reviewer'));

        self::assertSame($start + 11, $world->memory->policy->version($world->realm), 'Every change counts, one that changed nothing too.');
        self::assertEquals($world->clock->now(), $assignment->assignedAt);
        self::assertEquals(new \DateTimeImmutable('2026-12-01 10:00:00 UTC'), $assignment->expiresAt, 'Stored as every time is.');
        self::assertSame([
            ['yorvoid.role.create', 'role', 'reviewer', ['from' => 'member', 'label' => 'Reviewer']],
            ['yorvoid.role.relabel', 'role', 'reviewer', ['label' => 'Senior']],
            ['yorvoid.role.assign', 'principal', $id, ['expires_at' => '2026-12-01T10:00:00+00:00', 'role' => 'reviewer', 'scope' => 'site:1']],
            ['yorvoid.role.unassign', 'principal', $id, ['role' => 'reviewer', 'scope' => 'site:1']],
            ['yorvoid.ability.grant', 'role', 'reviewer', ['abilities' => ['acme.pages.publish']]],
            ['yorvoid.ability.revoke', 'role', 'reviewer', ['abilities' => ['acme.pages.publish']]],
            ['yorvoid.ability.set', 'role', 'reviewer', ['abilities' => ['acme.pages.view_drafts']]],
            ['yorvoid.ability.reset', null, null, ['abilities' => ['acme.pages.view_drafts']]],
            ['yorvoid.ability.reset', null, null, ['abilities' => 'all']],
            ['yorvoid.role.delete', 'role', 'reviewer', []],
        ], array_map(static fn (LogEntry $entry): array => [$entry->action, $entry->subjectType, $entry->subjectId, $entry->details], self::entries($world, 'yorvoid.role.', 'yorvoid.ability.')));

        foreach (self::entries($world, 'yorvoid.role.', 'yorvoid.ability.') as $entry) {
            self::assertSame(Outcome::Success, $entry->outcome);
            self::assertNull($entry->actor, 'The host\'s operator.');
        }
    }

    public function testRefusesUnknownRolesAndPrincipals(): void
    {
        $world = new PolicyWorld();

        foreach ([new AssignRole($world->realm, self::id($world->principal()), 'ghost'), new GrantAbilities($world->realm, 'ghost', []), new RelabelRole($world->realm, 'ghost', 'G'), new DeleteRole($world->realm, 'ghost'), new SetAbilities($world->realm, 'ghost', [])] as $command) {
            try {
                $world->asOperator($command);
                self::fail(\sprintf('%s ran for an unknown role.', $command::class));
            } catch (UnknownRole $error) {
                self::assertSame('There is no role "ghost" in the realm "acme".', $error->getMessage());
            }
        }

        $before = $world->memory->policy->version($world->realm);

        try {
            $world->asOperator(new AssignRole($world->realm, new PrincipalId(new UuidV7()), 'editor'));
            self::fail('A role was given to nobody.');
        } catch (UnknownPrincipal) {
            self::assertSame($before, $world->memory->policy->version($world->realm), 'Nothing changed.');
        }
    }

    public function testGivingASuperRoleOrAStrictAbilityTakesARecentProof(): void
    {
        $stepUp = new StubStepUpCheck(proven: false);
        $world = new PolicyWorld(stepUp: $stepUp);
        $admin = $world->principal('admin');
        $member = $world->principal();
        $world->asOperator(new GrantAbilities($world->realm, 'admin', ['acme.pages.purge']));

        foreach ([new AssignRole($world->realm, self::id($member), 'admin'), new GrantAbilities($world->realm, 'editor', ['acme.pages.purge']), new SetAbilities($world->realm, 'editor', ['acme.pages.purge']), new CreateRole($world->realm, 'purger', 'Purger', from: 'admin')] as $command) {
            try {
                $world->as($admin, $command);
                self::fail(\sprintf('%s ran without a recent proof.', $command::class));
            } catch (NotPermitted $refused) {
                self::assertSame(Verdict::Deny, $refused->decision->verdict);
                self::assertSame('Not proved recently.', $refused->decision->reason);
                self::assertCount(1, $refused->decision->remedies);
                self::assertInstanceOf(StepUpRemedy::class, $refused->decision->remedies[0]);
            }
        }

        self::assertSame(300, $stepUp->asked[0]->within->seconds, 'Within 5 minutes.');
        self::assertSame('yorvoid.role.assign', $stepUp->asked[0]->operation);

        $world->as($admin, new GrantAbilities($world->realm, 'editor', ['acme.pages.publish']));
        self::assertCount(4, $stepUp->asked, 'Nothing strict, nothing asked.');

        $stepUp->proven = true;
        $world->as($admin, new AssignRole($world->realm, self::id($member), 'admin'));
        self::assertTrue($world->allows($member, 'acme.pages.publish'));
    }

    public function testWithoutAStepUpCheckOnlyTheOperatorGivesThem(): void
    {
        $world = new PolicyWorld();
        $admin = $world->principal('admin');
        $member = $world->principal();

        try {
            $world->as($admin, new AssignRole($world->realm, self::id($member), 'admin'));
            self::fail('A super role was given without a proof.');
        } catch (NotPermitted $refused) {
            self::assertSame('Giving the super role "admin" takes a recent proof of who they are, and nothing checks one here.', $refused->decision->reason);
            self::assertSame([], $refused->decision->remedies);
        }

        $world->asOperator(new AssignRole($world->realm, self::id($member), 'admin'));
        $world->asOperator(new GrantAbilities($world->realm, 'admin', ['acme.pages.purge']));
        self::assertTrue($world->allows($member, 'acme.pages.purge'));
    }

    public function testNoEscalationSaysWhatTheyLack(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::ROLES_MANAGE, YorvoIDDeclarations::PERMISSIONS_MANAGE]));
        $manager = $world->principal('manager');
        $world->asOperator(new GrantAbilities($world->realm, 'member', ['acme.pages.publish']));

        $refusals = [
            'They\'d change what they don\'t hold themselves: acme.pages.publish.' => new ResetAbilities($world->realm),
            'They\'d change what they don\'t hold themselves: acme.pages.delete.' => new ResetAbilities($world->realm, ['acme.pages.delete']),
            'They\'d change what they don\'t hold themselves: acme.pages.view_drafts, acme.pages.publish, acme.pages.delete.' => new SetAbilities($world->realm, 'editor', []),
            '"admin" is a super role: only someone with one gives it or takes it.' => new AssignRole($world->realm, self::id($manager), 'admin'),
        ];

        foreach ($refusals as $reason => $command) {
            try {
                $world->as($manager, $command);
                self::fail($reason);
            } catch (NotPermitted $refused) {
                self::assertSame('A forbid applies: '.$reason, $refused->decision->reason);
            }
        }

        $world->as($manager, new SetAbilities($world->realm, 'manager', ['acme.sites.update', YorvoIDDeclarations::ROLES_MANAGE, YorvoIDDeclarations::PERMISSIONS_MANAGE]));
        $this->addToAssertionCount(1);
    }

    private static function id(Actor $actor): PrincipalId
    {
        \assert(null !== $actor->principalId);

        return $actor->principalId;
    }

    /**
     * @return list<LogEntry>
     */
    private static function entries(PolicyWorld $world, string ...$prefixes): array
    {
        $entries = [];

        foreach ($world->memory->log->entries($world->realm, 1) as $entry) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($entry->action, $prefix)) {
                    $entries[] = $entry;

                    break;
                }
            }
        }

        return $entries;
    }
}
