<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * Decisions (OPS-042): an editor of a server publishes one of its sites' pages, the role found three
 * levels up (page → site → server). Cached: as the request's second decision, with the shared pool
 * warm. Uncached: nothing cached, every read from PostgreSQL. A batch of 100 pages at once. And 100
 * cached decisions one by one in one request, as a page that shows 100 links decides them: each reads
 * the versions, since none is kept for the request.
 */
final class DecisionBench
{
    private const int PAGES = 100;

    /** @var array<string, array{PolicyWorld, TestPrincipal, list<ResourceRef>}> By whether it's cached */
    private static array $worlds = [];

    private PolicyWorld $world;

    private TestPrincipal $ada;

    /** @var list<ResourceRef> */
    private array $pages;

    public function setUp(string $subject): void
    {
        $cached = \in_array($subject, ['benchCachedDecision', 'benchHundredCachedDecisions'], true);
        [$this->world, $this->ada, $this->pages] = self::$worlds[$cached ? 'cached' : 'uncached'] ??= self::world($cached);
    }

    #[Measure(revolutions: 20)]
    public function benchCachedDecision(): void
    {
        $this->world->allows($this->ada, 'acme.pages.publish', $this->pages[0]) || throw new \LogicException('Refused.');
    }

    public function benchHundredCachedDecisions(): void
    {
        foreach ($this->pages as $page) {
            $this->world->allows($this->ada, 'acme.pages.publish', $page) || throw new \LogicException('Refused.');
        }
    }

    public function benchUncachedDecision(): void
    {
        $this->world->allows($this->ada, 'acme.pages.publish', $this->pages[0]) || throw new \LogicException('Refused.');
    }

    public function benchBatchDecision(): void
    {
        self::PAGES === \count($this->world->policy->decideMany($this->ada, 'acme.pages.publish', $this->world->realm, $this->pages, new Origin(OriginKind::Web))) || throw new \LogicException('Not every page.');
    }

    /**
     * @return array{PolicyWorld, TestPrincipal, list<ResourceRef>}
     */
    private static function world(bool $cached): array
    {
        $world = new PolicyWorld(stack: Postgres::stack($cached ? 'decision_cached' : 'decision'), cache: $cached ? new VersionedCache() : null);
        $server = new ResourceRef('server', '1');
        $site = new ResourceRef('site', '1');
        $world->asOperator(new AddRelation($world->realm, $site, 'parent', new Subject($server)));
        $pages = [];

        for ($i = 1; $i <= self::PAGES; ++$i) {
            $pages[] = $page = new ResourceRef('page', (string) $i);
            $world->asOperator(new AddRelation($world->realm, $page, 'parent', new Subject($site)));
        }

        $ada = $world->principal();
        $world->asOperator(new AssignRole($world->realm, $ada->principalId ?? throw new \LogicException(), 'editor', $server));

        if ($cached) {
            // The request's first decision reads what the others then find.
            $world->newRequest();
            $world->allows($ada, 'acme.pages.publish', $pages[0]) || throw new \LogicException('Refused.');
        }

        return [$world, $ada, $pages];
    }
}
