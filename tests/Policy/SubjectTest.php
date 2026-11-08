<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\AuthorsPublishTheirOwn;
use YorvoID\Tests\Policy\Fixtures\Classifications;
use YorvoID\Tests\Policy\Fixtures\ContentItem;
use YorvoID\Tests\Policy\Fixtures\Page;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The request's subject (G1) with the policy: rules and resolvers read it, roles count for the
 * resource it concerns, batches take it with each resource, and nothing keeps or caches it.
 */
final class SubjectTest extends DatabaseTestCase
{
    public function testRulesAndResolversReadTheSubject(): void
    {
        $world = self::world();
        $ada = $world->principal('member');
        $bob = $world->principal('member');
        $own = new Page('1', self::id($ada));

        self::assertTrue($this->allows($world, $ada, null, $own), 'Its author, read from the subject.');
        self::assertFalse($this->allows($world, $bob, null, $own));
        self::assertFalse($this->allows($world, $ada, new ResourceRef('page', '1'), null), 'Without the subject, the rule has nothing to read.');

        $secret = $this->decide($world, $ada, null, new Page('2', self::id($ada), 'secret'));
        self::assertFalse($secret->permits());
        self::assertSame('A forbid applies: Secret pages aren\'t published.', $secret->reason, 'The classification, resolved from the subject.');
    }

    public function testRolesCountForTheResourceTheSubjectConcerns(): void
    {
        $world = self::world();
        $ada = $world->principal('member');
        $carol = $world->principal('member');
        $dan = $world->principal('editor');
        $world->asOperator(new AssignRole($world->realm, self::id($carol), 'editor', new ResourceRef('page', '1')));
        $page = new Page('1', self::id($ada));

        self::assertTrue($this->allows($world, $carol, null, $page), 'Her role on the page it concerns.');
        self::assertTrue($this->allows($world, $carol, new ResourceRef('page', '1'), $page));
        self::assertFalse($this->allows($world, $carol, null, new Page('2', self::id($ada))));
        self::assertTrue($this->allows($world, $carol, new ResourceRef('page', '1'), new \stdClass()), 'Any subject, with the resource given.');
        self::assertFalse($this->allows($world, $carol, null, new \stdClass()), 'A subject that concerns no resource: roles on resources don\'t count.');
        self::assertTrue($this->allows($world, $dan, null, new \stdClass()), 'Those on the whole realm do.');

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The subject of "acme.pages.publish" concerns page:1, not page:2.');
        $this->allows($world, $carol, new ResourceRef('page', '2'), $page);
    }

    public function testABatchTakesEachResourceWithItsSubject(): void
    {
        $world = self::world();
        $ada = $world->principal('member');
        $world->relationStore->add(new RelationTuple($world->realm, new ResourceRef('page', '3'), 'editor', Subject::principal(self::id($ada))));
        $rows = [new Page('1', self::id($ada)), [new ResourceRef('content', '2'), new ContentItem(self::id($ada))], new ResourceRef('page', '3'), [new ResourceRef('page', '4'), new \stdClass()], new Page('5', self::id($ada), 'secret')];

        $decisions = $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, $rows, new Origin(OriginKind::Web));

        self::assertSame(['page:1', 'content:2', 'page:3'], array_map(strval(...), $decisions->permitted()), 'Keyed by the resource, given or concerned.');
        self::assertSame('A forbid applies: Secret pages aren\'t published.', $decisions->for(new ResourceRef('page', '5'))->reason);
        self::assertFalse($decisions->allows(new ResourceRef('page', '4')));

        foreach (['acme', [new ResourceRef('page', '1')], [new ResourceRef('page', '1'), 'acme'], ['page:1', new \stdClass()], new \stdClass()] as $wrong) {
            try {
                $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, [$wrong], new Origin(OriginKind::Web)); // @phpstan-ignore argument.type
                self::fail('A batch was given '.get_debug_type($wrong).'.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith(\sprintf('decideMany() was given %s for "acme.pages.publish".', get_debug_type($wrong)), $error->getMessage());
            }
        }

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The subject of "acme.pages.publish" concerns page:1, not page:9.');
        $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, [[new ResourceRef('page', '9'), new Page('1', self::id($ada))]], new Origin(OriginKind::Web));
    }

    public function testNothingKeepsOrCachesTheSubject(): void
    {
        $pool = new ArrayAdapter();
        $world = self::world(new VersionedCache($pool, Installation::named('test')));
        $ada = $world->principal('member');
        $world->asOperator(new AssignRole($world->realm, self::id($ada), 'editor', new ResourceRef('page', '1')));
        $world->newRequest();
        $page = new Page('1', self::id($ada), 'classified-zebra');
        $kept = \WeakReference::create($page);

        self::assertTrue($this->allows($world, $ada, null, $page));
        self::assertCount(1, $world->policy->decideMany($ada, 'acme.pages.publish', $world->realm, [$page], new Origin(OriginKind::Web)));
        $keys = array_keys($pool->getValues());
        self::assertNotSame([], $keys);
        self::assertStringNotContainsString('zebra', serialize($pool->getValues()), 'Neither in the keys nor the values.');

        $world->newRequest();
        self::assertTrue($this->allows($world, $ada, null, new Page('1', self::id($ada), 'classified-yak')));
        self::assertSame($keys, array_keys($pool->getValues()), 'Another subject of the same resource reads the same entries.');

        unset($page);
        gc_collect_cycles();
        self::assertNull($kept->get(), 'Nothing kept it beyond its requests.');
    }

    private static function world(?VersionedCache $cache = null): PolicyWorld
    {
        return new PolicyWorld(resolvers: [new Classifications()], rulesOf: static fn ($attributes): array => [new AuthorsPublishTheirOwn($attributes)], cache: $cache);
    }

    private function decide(PolicyWorld $world, ActingPrincipal $actor, ?ResourceRef $resource, ?object $subject): Decision
    {
        return $world->policy->decide($actor, 'acme.pages.publish', $world->realm, $resource, new Origin(OriginKind::Web), $subject);
    }

    private function allows(PolicyWorld $world, ActingPrincipal $actor, ?ResourceRef $resource, ?object $subject): bool
    {
        $allows = $world->policy->allows($actor, 'acme.pages.publish', $world->realm, $resource, new Origin(OriginKind::Web), $subject);
        self::assertSame($allows, $this->decide($world, $actor, $resource, $subject)->permits());

        return $allows;
    }

    private static function id(ActingPrincipal $principal): PrincipalId
    {
        \assert(null !== $principal->principalId);

        return $principal->principalId;
    }
}
