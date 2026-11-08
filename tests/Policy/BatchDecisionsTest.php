<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\StatementLog;

/**
 * Batch decisions (AUZ-030, AUZ-032) and what a request reads (G2): a batch decides each resource as
 * decide() would, in as many queries for a hundred resources as for one, and a request reads the
 * policy once however many decisions it makes.
 */
final class BatchDecisionsTest extends DatabaseTestCase
{
    public function testABatchDecidesEachResourceAsOneByOne(): void
    {
        $world = new PolicyWorld();
        [$ada, $pages] = $this->site($world, 6);
        $pages[] = new ResourceRef('page', 'orphan');
        $pages[] = $pages[0];

        $decisions = $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, $pages, new Origin(OriginKind::Web));

        self::assertCount(8, $decisions);

        foreach ($pages as $page) {
            $alone = $world->decide($ada, 'acme.pages.publish', $page);
            self::assertSame([$alone->verdict, $alone->reason], [$decisions->for($page)->verdict, $decisions->for($page)->reason], (string) $page);
            self::assertSame($alone->permits(), $decisions->allows($page));
        }

        self::assertEquals([$pages[0], $pages[2], $pages[4], $pages[5], $pages[0]], $decisions->permitted(), 'The site\'s pages by the scoped role, page 5 by its relation; in order, as asked.');
        $order = [];

        foreach ($decisions as $page => $decision) {
            \assert($page instanceof ResourceRef && $decision instanceof Decision);
            $order[] = [(string) $page, $decision->verdict];
        }

        self::assertSame((string) $pages[1], $order[1][0]);
        self::assertSame(Verdict::Deny, $order[1][1]);
        self::assertCount(0, $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, [], new Origin(OriginKind::Web)));

        try {
            $decisions->for(new ResourceRef('page', 'unasked'));
            self::fail('A decision about a resource not asked about.');
        } catch (\OutOfBoundsException $e) {
            self::assertSame('page:unasked wasn\'t asked about.', $e->getMessage());
        }
    }

    public function testABatchsQueriesDontGrowWithIt(): void
    {
        $statements = new StatementLog();
        $world = new PolicyWorld(stack: $this->stack('sqlite', $statements));
        [$ada, $pages] = $this->site($world, 100);
        $counts = [];

        foreach ([1, 10, 100] as $size) {
            $statements->take();
            $decisions = $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, \array_slice($pages, 0, $size), new Origin(OriginKind::Web));
            $counts[$size] = \count($statements->take());
            self::assertCount($size, $decisions);
        }

        self::assertSame([1 => $counts[1], 10 => $counts[1], 100 => $counts[1]], $counts, 'Statements for 1, 10 and 100 pages.');
        self::assertLessThanOrEqual(10, $counts[1], 'Versions, the principal, assignments, groups, two levels of parents and the editors.');
    }

    public function testARequestReadsThePolicyOnce(): void
    {
        $statements = new StatementLog();
        $stack = $this->stack('sqlite', $statements);
        $world = new PolicyWorld(stack: $stack, cache: new VersionedCache());
        [$ada, $pages] = $this->site($world, 3);
        $world->newRequest();
        $statements->take();

        self::assertTrue($world->allows($ada, 'acme.pages.publish', $pages[0]));
        self::assertNotSame([], self::policyReads($statements->take()), 'The first decision reads the policy.');

        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($world->allows($ada, 'acme.pages.publish', $pages[0]));
            self::assertFalse($world->allows($ada, 'acme.sites.update', $pages[0]));
        }

        self::assertSame([], self::policyReads($statements->take()), 'Forty more decisions read nothing of it again.');

        // Another server makes her a manager: this request goes on with what it read, the next reads it.
        $other = new DbalPolicyStore($this->database('sqlite'));
        $other->bump($world->realm);
        $other->assign(new RoleAssignment($world->realm, self::id($ada), 'manager', new \DateTimeImmutable('2026-11-01')));

        self::assertFalse($world->allows($ada, 'acme.sites.update', $pages[0]), 'One request, one state of the policy.');
        $world->newRequest();
        self::assertTrue($world->allows($ada, 'acme.sites.update', $pages[0]), 'The next request reads the new version.');
    }

    public function testARequestThatChangesThePolicyReadsItsChanges(): void
    {
        $world = new PolicyWorld(stack: $this->stack('sqlite'), cache: new VersionedCache());
        [$ada, $pages] = $this->site($world, 1);
        $world->newRequest();

        self::assertFalse($world->allows($ada, 'acme.sites.update', $pages[0]));
        $world->asOperator(new AssignRole($world->realm, self::id($ada), 'manager'));
        self::assertTrue($world->allows($ada, 'acme.sites.update', $pages[0]), 'Its own change counts at once.');

        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('page', 'new'), 'editor', Subject::principal(self::id($ada))));
        self::assertTrue($world->allows($ada, 'acme.pages.publish', new ResourceRef('page', 'new')), 'And its relations.');
    }

    /**
     * Ada, a member who edits site "a" by a scoped role, and pages alternately on sites "a" and "b",
     * both on server "s"; she's an editor of page 5 by a relation.
     *
     * @return array{ActingPrincipal, list<ResourceRef>}
     */
    private function site(PolicyWorld $world, int $count): array
    {
        $ada = $world->principal('member');
        $world->asOperator(new AssignRole($world->realm, self::id($ada), 'editor', new ResourceRef('site', 'a')));
        $server = new Subject(new ResourceRef('server', 's'));
        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('site', 'a'), 'parent', $server));
        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('site', 'b'), 'parent', $server));
        $pages = [];

        for ($i = 1; $i <= $count; ++$i) {
            $page = new ResourceRef('page', (string) $i);
            $world->relationStore->add(new RelationTuple($world->realm, $page, 'parent', new Subject(new ResourceRef('site', 1 === $i % 2 ? 'a' : 'b'))));
            $pages[] = $page;
        }

        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('page', '6'), 'editor', Subject::principal(self::id($ada))));

        return [$ada, $pages];
    }

    private static function id(ActingPrincipal $principal): PrincipalId
    {
        \assert(null !== $principal->principalId);

        return $principal->principalId;
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private static function policyReads(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/FROM "?yorvoid_(policy|relation)_/i', $sql)));
    }
}
