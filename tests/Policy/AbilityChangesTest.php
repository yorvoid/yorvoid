<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Policy\AbilityChanges;

/**
 * Only differences from the defaults are kept (AUZ-002), normalised as Quire normalises them.
 */
final class AbilityChangesTest extends TestCase
{
    public function testKeepsOnlyWhatDiffersFromTheDefaults(): void
    {
        $changes = new AbilityChanges()
            ->change('acme.pages.view_drafts', 'member', true, ['editor'])
            ->change('acme.pages.delete', 'editor', false, ['editor'])
            ->change('acme.pages.publish', 'editor', true, ['editor'])
            ->change('acme.pages.publish', 'member', false, ['editor']);

        self::assertSame(['acme.pages.view_drafts' => ['member']], $changes->grant, 'Giving a role what it has by default records nothing.');
        self::assertSame(['acme.pages.delete' => ['editor']], $changes->revoke, 'Taking what a role doesn\'t have by default records nothing.');
        self::assertSame(['acme.pages.delete', 'acme.pages.view_drafts'], $changes->abilities());

        $back = $changes->change('acme.pages.delete', 'editor', true, ['editor'])->change('acme.pages.view_drafts', 'member', false, ['editor']);
        self::assertTrue($back->isEmpty(), 'Undoing each change leaves nothing.');
        self::assertFalse($changes->isEmpty());
    }

    public function testTheRolesWithAnAbilityAreItsDefaultsWithTheChanges(): void
    {
        $changes = new AbilityChanges(['acme.pages.publish' => ['member', 'reviewer']], ['acme.pages.publish' => ['editor']]);

        self::assertSame(['admin', 'member', 'reviewer'], $changes->rolesWith('acme.pages.publish', ['editor', 'admin']), 'The defaults first, then what was given, without what was taken.');
        self::assertSame(['editor', 'editor2'], $changes->rolesWith('acme.pages.view', ['editor', 'editor2', 'editor']));
        self::assertSame([], $changes->rolesWith('acme.pages.view', []));
    }

    public function testForgetsARoleAndResetsAbilities(): void
    {
        $changes = new AbilityChanges(['a.x' => ['member', 'helper'], 'a.y' => ['helper']], ['a.z' => ['helper', 'editor']]);

        $without = $changes->withoutRole('helper');
        self::assertSame(['a.x' => ['member']], $without->grant);
        self::assertSame(['a.z' => ['editor']], $without->revoke);

        $reset = $changes->reset(['a.x', 'a.z']);
        self::assertSame(['a.y' => ['helper']], $reset->grant);
        self::assertSame([], $reset->revoke);
        self::assertTrue($changes->reset()->isEmpty());
        self::assertTrue($changes->reset([])->equals($changes));
    }

    public function testEqualChangesAreEqualHoweverTheyWereMade(): void
    {
        $one = new AbilityChanges(['b.y' => ['z', 'a', 'a'], 'a.x' => ['m']], ['c.z' => [], 'b.y' => ['q']]);
        $other = new AbilityChanges()->change('a.x', 'm', true, [])->change('b.y', 'a', true, [])->change('b.y', 'z', true, [])->change('b.y', 'q', false, ['q']);

        self::assertSame(['a.x' => ['m'], 'b.y' => ['a', 'z']], $one->grant, 'Sorted, each role once.');
        self::assertSame(['b.y' => ['q']], $one->revoke, 'An empty list is dropped.');
        self::assertTrue($one->equals($other));
        self::assertFalse($one->equals(new AbilityChanges($one->grant)));
        self::assertFalse($one->equals(new AbilityChanges([], $one->revoke)));
    }

    public function testAChangeKeepsWhatOtherRolesHad(): void
    {
        $changes = new AbilityChanges(['a.x' => ['member', 'helper']], ['a.x' => ['editor', 'admin'], 'a.y' => ['editor', 'admin']]);
        $defaults = ['editor', 'admin'];

        self::assertSame(['admin'], $changes->change('a.x', 'editor', true, $defaults)->revoke['a.x'], 'Giving one default back keeps the other\'s revoke.');
        self::assertSame(['helper'], $changes->change('a.x', 'member', false, $defaults)->grant['a.x'], 'Taking one grant keeps the other.');
        self::assertSame(['helper', 'member'], $changes->change('a.x', 'admin', true, $defaults)->grant['a.x'], 'A default given back records no grant.');
        self::assertSame(['admin', 'editor', 'owner'], $changes->change('a.y', 'owner', false, ['editor', 'admin', 'owner'])->revoke['a.y'], 'Another default taken joins the revokes.');
        self::assertSame(['admin'], $changes->change('a.x', 'editor', false, [])->revoke['a.x'], 'A role taken what it doesn\'t have by default leaves the others.');
        self::assertSame(['helper', 'member', 'reviewer'], $changes->change('a.x', 'reviewer', true, $defaults)->grant['a.x']);
    }

    public function testEmptyOnlyWithoutGrantsAndRevokes(): void
    {
        self::assertTrue(new AbilityChanges()->isEmpty());
        self::assertFalse(new AbilityChanges(['a.x' => ['member']])->isEmpty());
        self::assertFalse(new AbilityChanges([], ['a.x' => ['member']])->isEmpty());
        self::assertSame(['a.x' => ['member'], 'a.y' => ['member']], new AbilityChanges(['a.x' => ['member', 'helper'], 'a.y' => ['member']])->withoutRole('helper')->grant);
    }
}
