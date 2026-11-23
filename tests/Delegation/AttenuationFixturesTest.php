<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Delegation\Attenuation;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\Purposes;
use YorvoID\Testing\Ids;

/**
 * The attenuation model's fixtures (spec/delegation/fixtures.json, from attenuation.als): for each
 * parent and child the model enumerates, the PHP check of one link finds the child within its parent
 * exactly when the model does.
 */
final class AttenuationFixturesTest extends TestCase
{
    public function testEveryLinkIsWithinItsParentExactlyWhenTheModelSaysSo(): void
    {
        $json = (string) file_get_contents(\dirname(__DIR__, 2).'/spec/delegation/fixtures.json');
        /** @var list<array{array<string, string|null>, array<string, mixed>, array<string, mixed>, bool}> $fixtures */
        $fixtures = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        self::assertCount(4617, $fixtures, 'The model\'s sample moved: run node delegation/fixtures.mjs.');
        $within = 0;
        $realm = new Realm('acme');
        [$root, $middle, $leaf] = [new PrincipalId(Ids::next()), new PrincipalId(Ids::next()), new PrincipalId(Ids::next())];

        foreach ($fixtures as $i => [$purposes, $given, $child]) {
            $parent = self::delegation($realm, $given, $root, $middle, null, 1);
            $passed = self::delegation($realm, $child, $middle, $leaf, $parent->id, 1, $root);
            $found = new Attenuation(new Purposes($purposes));
            $outside = $found->inconsistent($passed, $parent) ?? $found->outside($passed, $parent);
            $expected = $fixtures[$i][3];

            self::assertSame($expected, null === $outside, \sprintf('Fixture %d: the model says %s, PHP %s.', $i, $expected ? 'within' : 'outside', $outside ?? 'within'));
            $within += $expected ? 1 : 0;
        }

        self::assertGreaterThan(100, $within, 'Few links within: the sample lost its point.');
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function delegation(Realm $realm, array $d, PrincipalId $delegator, PrincipalId $delegate, ?DelegationId $parent, int $level, ?PrincipalId $root = null): Delegation
    {
        $strings = static function (string $key) use ($d): array {
            $list = [];

            foreach ((array) $d[$key] as $item) {
                $list[] = \is_string($item) ? $item : throw new \UnexpectedValueException('A fixture\'s list holds something else than text.');
            }

            return $list;
        };
        $atoms = array_map(static fn (string $other): AttributeIs => new AttributeIs('other', $other), $strings('others'));

        if (null !== $d['ids']) {
            // An id atom with no ids matches nothing; the PHP selector names at least one, so an empty one
            // is a name nothing has.
            $atoms[] = new IdIn([] === $d['ids'] ? ['none'] : $strings('ids'));
        }

        $at = static fn (mixed $index): \DateTimeImmutable => new \DateTimeImmutable(\sprintf('2026-11-0%d 12:00:00 UTC', 1 + (\is_int($index) ? $index : 0)));
        $depth = \is_int($d['depth']) ? $d['depth'] : 0;

        return new Delegation(
            new DelegationId(Ids::next()),
            $realm,
            $delegator,
            $delegate,
            $parent,
            $root ?? $delegator,
            null === $parent ? 0 : $level,
            $strings('actions'),
            $strings('actions'),
            new Selector('type0', $atoms),
            \is_string($d['purpose']) ? $d['purpose'] : null,
            $at($d['from']),
            $at($d['until']),
            $strings('redelegable'),
            $depth,
            null,
            null,
            'decision',
            1,
            $at(0),
        );
    }
}
