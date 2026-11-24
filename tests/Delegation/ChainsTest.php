<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Delegation\Chains;
use YorvoID\Delegation\Delegation;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

/**
 * A chain checked from its leaf up: as long as the limit and no longer, every principal on it active,
 * and a link without a parent its root's own.
 */
final class ChainsTest extends TestCase
{
    private PolicyWorld $world;

    /** @var list<PrincipalId> */
    private array $people;

    protected function setUp(): void
    {
        $this->world = new PolicyWorld(delegations: true);
        $this->people = array_map(fn (int $i): PrincipalId => $this->world->principal()->principalId ?? throw new \LogicException(), range(0, 4));
    }

    public function testAChainAsLongAsTheLimitHoldsAndOneLongerIsntKnown(): void
    {
        $links = $this->chain(4);

        self::assertTrue($this->check($links[2])->valid, 'Three links: the limit.');
        $deeper = $this->check($links[3]);
        self::assertNull($deeper->valid);
        self::assertSame('deeper than the limit', $deeper->reason);
    }

    public function testEveryPrincipalOnItIsActiveTheDelegatorsToo(): void
    {
        [$root, $leaf] = $this->chain(2);
        $this->world->asOperator(new SuspendPrincipal($this->world->realm, $this->people[0]));

        $check = $this->check($leaf);
        self::assertFalse($check->valid);
        self::assertSame(\sprintf('the principal %s isn\'t active', $this->people[0]), $check->reason, 'The root, delegator of the first link only.');

        $gone = $this->link(new PrincipalId(Ids::next()), $this->people[1], null, null, 0, 0);
        self::assertSame(\sprintf('the principal %s isn\'t active', $gone->delegator), $this->check($gone)->reason, 'One that isn\'t there.');
    }

    public function testALinkWithoutAParentIsItsRootsOwn(): void
    {
        $orphan = $this->link($this->people[1], $this->people[2], null, $this->people[0], 1, 0);
        $check = $this->check($orphan);

        self::assertNull($check->valid);
        self::assertSame(\sprintf('the delegation %s has no parent but isn\'t its root\'s', $orphan->id), $check->reason);
        self::assertNull($this->check($this->link($this->people[1], $this->people[2], null, $this->people[1], 1, 0))->valid, 'Its own, but not at the top.');
    }

    private function check(Delegation $leaf): \YorvoID\Delegation\ChainCheck
    {
        return new Chains($this->world->memory->delegations, $this->world->principalStore)->check($leaf, new \DateTimeImmutable('2026-11-05 12:00:00 UTC'));
    }

    /**
     * Links from people[0] down, each within its parent, kept.
     *
     * @return list<Delegation>
     */
    private function chain(int $length): array
    {
        $links = [];

        for ($i = 0; $i < $length; ++$i) {
            $parent = $links[$i - 1] ?? null;
            $links[] = $link = $this->link($this->people[$i], $this->people[$i + 1], $parent?->id, $this->people[0], $i, $length - 1 - $i);
            $this->world->memory->delegations->add($link);
        }

        return $links;
    }

    private function link(PrincipalId $delegator, PrincipalId $delegate, ?DelegationId $parent, ?PrincipalId $root, int $depth, int $remaining): Delegation
    {
        return new Delegation(new DelegationId(Ids::next()), $this->world->realm, $delegator, $delegate, $parent, $root ?? $delegator, $depth, ['acme.pages.publish'], ['acme.pages.publish'], new Selector('page'), null, new \DateTimeImmutable('2026-11-01 12:00:00 UTC'), new \DateTimeImmutable('2026-11-10 12:00:00 UTC'), $remaining > 0 ? ['acme.pages.publish'] : [], $remaining, null, null, 'decision', 1, new \DateTimeImmutable('2026-11-01 12:00:00 UTC'));
    }
}
