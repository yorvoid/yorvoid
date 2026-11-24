<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Delegation\Attenuation;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\Purposes;
use YorvoID\Testing\Ids;

/**
 * One link of a chain against its parent: the records agree (inconsistent()), and the child is within
 * its parent on each dimension (outside()), each named; a delegation's window; purposes form a tree.
 */
final class LinksTest extends TestCase
{
    private PrincipalId $root;

    private PrincipalId $middle;

    private PrincipalId $leaf;

    protected function setUp(): void
    {
        [$this->root, $this->middle, $this->leaf] = [new PrincipalId(Ids::next()), new PrincipalId(Ids::next()), new PrincipalId(Ids::next())];
    }

    public function testEachDisagreementOfTheRecordsIsNamed(): void
    {
        $parent = $this->parent();
        $attenuation = new Attenuation();
        self::assertNull($attenuation->inconsistent($this->child($parent), $parent));
        self::assertNull($attenuation->outside($this->child($parent), $parent));

        foreach ([
            'another realm' => $this->child($parent, realm: new Realm('globex')),
            'another parent' => $this->child($parent, parent: new DelegationId(Ids::next())),
            'a delegator the parent wasn\'t given to' => $this->child($parent, delegator: $this->leaf),
            'another root' => $this->child($parent, root: $this->middle),
            'a depth that isn\'t its parent\'s + 1' => $this->child($parent, depth: 2),
        ] as $why => $child) {
            self::assertSame($why, $attenuation->inconsistent($child, $parent));
        }

        self::assertSame('another parent', $attenuation->inconsistent($this->link($this->middle, $this->leaf, null, $this->root, 1), $parent), 'None at all.');
    }

    public function testEachWayOutsideItsParentIsNamed(): void
    {
        $parent = $this->parent();
        $attenuation = new Attenuation();

        foreach ([
            'more to pass on than its parent allows' => $this->child($parent, actions: ['a'], redelegable: ['b'], remainingDepth: 1),
            'another task than its parent\'s' => $this->child($parent, execution: 'run-2'),
            'a weaker proof than its parent asks' => $this->child($parent, stepUp: null),
        ] as $why => $child) {
            self::assertSame($why, $attenuation->outside($child, $parent));
        }

        foreach ([new StepUpRemedy(Duration::minutes(5), false, true), new StepUpRemedy(Duration::minutes(5), true, false), new StepUpRemedy(Duration::minutes(6), true, true)] as $i => $proof) {
            self::assertSame('a weaker proof than its parent asks', $attenuation->outside($this->child($parent, stepUp: $proof), $parent), 'Proof '.$i.': without a factor the parent asks, or older.');
        }

        self::assertNull($attenuation->outside($this->child($parent, stepUp: new StepUpRemedy(Duration::minutes(1), true, true)), $parent), 'More recent.');

        $free = $this->link($this->root, $this->middle, null, $this->root, 0, redelegable: ['a', 'b']);
        self::assertNull($attenuation->outside($this->child($free, execution: 'any', stepUp: null), $free), 'A parent bound to no task, asking no proof.');
    }

    public function testADelegationIsCurrentFromItsStartUpToItsEnd(): void
    {
        $delegation = $this->parent();

        self::assertFalse($delegation->isCurrentAt(new \DateTimeImmutable('2026-11-01 11:59:59 UTC')));
        self::assertTrue($delegation->isCurrentAt(new \DateTimeImmutable('2026-11-01 12:00:00 UTC')));
        self::assertFalse($delegation->isCurrentAt(new \DateTimeImmutable('2026-11-10 12:00:00 UTC')));
        self::assertFalse($delegation->isCurrentAt(new \DateTimeImmutable('2026-11-11 12:00:00 UTC')));
    }

    public function testPurposesFormATree(): void
    {
        foreach ([
            'The purpose "a" is under "z", which isn\'t registered.' => ['a' => 'z'],
            'The purposes loop at "a".' => ['a' => 'b', 'b' => 'a'],
        ] as $message => $parents) {
            try {
                new Purposes($parents);
                self::fail('Accepted: '.$message);
            } catch (IntegratorError $e) {
                self::assertStringStartsWith($message."\n", $e->getMessage());
            }
        }
    }

    private function parent(): Delegation
    {
        return $this->link($this->root, $this->middle, null, $this->root, 0, redelegable: ['a', 'b'], execution: 'run-1', stepUp: self::proof());
    }

    private static function proof(): StepUpRemedy
    {
        return new StepUpRemedy(Duration::minutes(5), true, true);
    }

    /**
     * A child of the parent, within it unless told otherwise: its proof by default, false.
     *
     * @param list<string> $actions
     * @param list<string> $redelegable
     */
    private function child(Delegation $of, ?Realm $realm = null, ?DelegationId $parent = null, ?PrincipalId $delegator = null, ?PrincipalId $root = null, ?int $depth = null, array $actions = ['a'], array $redelegable = [], int $remainingDepth = 0, ?string $execution = 'run-1', StepUpRemedy|false|null $stepUp = false): Delegation
    {
        return $this->link($delegator ?? $of->delegate, $this->leaf, $parent ?? $of->id, $root ?? $of->root, $depth ?? $of->depth + 1, $realm, $actions, $redelegable, $remainingDepth, $execution, false === $stepUp ? self::proof() : $stepUp);
    }

    /**
     * @param list<string> $actions
     * @param list<string> $redelegable
     */
    private function link(PrincipalId $delegator, PrincipalId $delegate, ?DelegationId $parent, PrincipalId $root, int $depth, ?Realm $realm = null, array $actions = ['a', 'b'], array $redelegable = [], int $remainingDepth = 2, ?string $execution = null, ?StepUpRemedy $stepUp = null): Delegation
    {
        return new Delegation(new DelegationId(Ids::next()), $realm ?? new Realm('acme'), $delegator, $delegate, $parent, $root, $depth, $actions, $actions, new Selector('page'), null, new \DateTimeImmutable('2026-11-01 12:00:00 UTC'), new \DateTimeImmutable('2026-11-10 12:00:00 UTC'), $redelegable, $remainingDepth, $execution, $stepUp, 'decision', 1, new \DateTimeImmutable('2026-11-01 12:00:00 UTC'));
    }
}
