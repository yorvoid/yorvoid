<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\VersionMemo;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\UnderDelegation;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Delegation\Change\IssueDelegation;
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
 * the versions, since none is kept for the request. Delegated: uncached, by an agent at the end of a
 * chain of three delegations from that editor, each link and the root's role read from PostgreSQL.
 */
final class DecisionBench
{
    private const int PAGES = 100;

    /** @var array<string, array{PolicyWorld, TestPrincipal, list<ResourceRef>}> By whether it's cached */
    private static array $worlds = [];

    /** @var array{PolicyWorld, UnderDelegation, list<ResourceRef>}|null */
    private static ?array $delegated = null;

    private PolicyWorld $world;

    private TestPrincipal $ada;

    /** @var list<ResourceRef> */
    private array $pages;

    private ?UnderDelegation $agent = null;

    public function setUp(string $subject): void
    {
        if ('benchDelegatedDecision' === $subject) {
            [$this->world, $this->agent, $this->pages] = self::$delegated ??= self::delegated();

            return;
        }

        $cached = \in_array($subject, ['benchCachedDecision', 'benchHundredCachedDecisions', 'benchHundredMemoizedDecisions'], true);
        $memo = 'benchHundredMemoizedDecisions' === $subject;
        [$this->world, $this->ada, $this->pages] = self::$worlds[$memo ? 'memo' : ($cached ? 'cached' : 'uncached')] ??= self::world($cached, $memo);
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

    /**
     * The same, with the opt-in memo: one version read for the request.
     */
    public function benchHundredMemoizedDecisions(): void
    {
        // A request of its own each time: the memo reads the versions once in it.
        $this->world->memo?->reset();

        foreach ($this->pages as $page) {
            $this->world->allows($this->ada, 'acme.pages.publish', $page) || throw new \LogicException('Refused.');
        }
    }

    public function benchUncachedDecision(): void
    {
        $this->world->allows($this->ada, 'acme.pages.publish', $this->pages[0]) || throw new \LogicException('Refused.');
    }

    #[Measure(revolutions: 20)]
    public function benchDelegatedDecision(): void
    {
        $this->world->allows($this->agent ?? throw new \LogicException(), 'acme.pages.publish', $this->pages[0]) || throw new \LogicException('Refused.');
    }

    public function benchBatchDecision(): void
    {
        self::PAGES === \count($this->world->policy->decideMany($this->ada, 'acme.pages.publish', $this->world->realm, $this->pages, new Origin(OriginKind::Web))) || throw new \LogicException('Not every page.');
    }

    /**
     * @return array{PolicyWorld, UnderDelegation, list<ResourceRef>}
     */
    private static function delegated(): array
    {
        [$world, $ada, $pages] = self::world(false, delegations: true);
        $until = new \DateTimeImmutable('2026-11-14 20:00:00');
        $bea = $world->principal();
        $cara = $world->principal();
        $bot = new TestPrincipal($world->realm, $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: $cara->principalId))->id);
        $link = null;

        foreach ([[$ada, $bea, 2], [$bea, $cara, 1], [$cara, $bot, 0]] as [$from, $to, $depth]) {
            $link = $world->as($from, new IssueDelegation($world->realm, $to->principalId ?? throw new \LogicException(), ['acme.pages.publish'], new Selector('page', [new IdIn([$pages[0]->id])]), $until, redelegable: $depth > 0 ? ['acme.pages.publish'] : [], depth: $depth, parent: $link?->id));
        }

        return [$world, new UnderDelegation($bot, $link->id), $pages];
    }

    /**
     * @return array{PolicyWorld, TestPrincipal, list<ResourceRef>}
     */
    private static function world(bool $cached, bool $memo = false, bool $delegations = false): array
    {
        $stack = $delegations ? 'decision_delegated' : ($memo ? 'decision_memo' : ($cached ? 'decision_cached' : 'decision'));
        $world = new PolicyWorld(stack: Postgres::stack($stack), cache: $cached ? new VersionedCache() : null, memo: $memo ? new VersionMemo() : null, delegations: $delegations);
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
