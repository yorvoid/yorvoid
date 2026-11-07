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
}
