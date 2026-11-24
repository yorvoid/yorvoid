<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Selector;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Exception\IntegratorError;

/**
 * A resource selector, and when one is within another (M3-1's language): the same type, every condition
 * of the other, more of them, or a subset of its IDs. Syntactic, so incomplete on purpose.
 */
final class SelectorTest extends TestCase
{
    public function testAChildWithinItsParentHasItsTypeAndConditionsAndMaybeMore(): void
    {
        $drafts = new AttributeIs('status', 'draft');
        $mine = new RelatedTo('editor', new ResourceRef('principal', 'ana'));
        $parent = new Selector('page', [$drafts, new IdIn(['p-1', 'p-2', 'p-3'])]);

        self::assertTrue(new Selector('page', [$drafts, new IdIn(['p-1', 'p-2', 'p-3'])])->within($parent), 'The same.');
        self::assertTrue(new Selector('page', [$mine, $drafts, new IdIn(['p-2'])])->within($parent), 'More conditions, fewer IDs.');
        self::assertFalse(new Selector('page', [$drafts])->within($parent), 'Without the IDs: wider.');
        self::assertFalse(new Selector('page', [$drafts, new IdIn(['p-1', 'p-4'])])->within($parent), 'An ID outside the parent\'s.');
        self::assertFalse(new Selector('page', [new IdIn(['p-1'])])->within($parent), 'Without a condition.');
        self::assertFalse(new Selector('page', [new AttributeIs('status', 'live'), new IdIn(['p-1'])])->within($parent), 'Another value.');
        self::assertFalse(new Selector('site', [$drafts, new IdIn(['p-1'])])->within($parent), 'Another type.');
        self::assertFalse(new Selector('page', [new AttributeIs('status', 1), new IdIn(['p-1'])])->within(new Selector('page', [new AttributeIs('status', '1')])), 'A number isn\'t its text.');
        self::assertTrue(new Selector('page')->within(new Selector('page')));
        self::assertTrue(new Selector('page', [$mine, $drafts])->within(new Selector('page', [$mine, $drafts])), 'Every one of the parent\'s conditions, not only the first.');
        self::assertFalse(new Selector('page', [new IdIn(['p-1'])])->within(new Selector('page', [new IdIn(['p-1', 'p-2']), $mine])), 'IDs within, a condition after them missing.');
    }

    public function testItsFormIsCanonicalAndRoundTrips(): void
    {
        $a = new Selector('page', [new IdIn(['p-2', 'p-1', 'p-2']), new AttributeIs('status', 'draft'), new RelatedTo('editor', new ResourceRef('principal', 'ana'))]);
        $b = new Selector('page', [new RelatedTo('editor', new ResourceRef('principal', 'ana')), new AttributeIs('status', 'draft'), new IdIn(['p-1', 'p-2'])]);

        self::assertSame($a->toArray(), $b->toArray(), 'Order and repeats don\'t matter.');
        self::assertEquals($a, Selector::fromArray($a->toArray()));
        self::assertTrue($a->admitsTypeAndId(new ResourceRef('page', 'p-1')));
        self::assertFalse($a->admitsTypeAndId(new ResourceRef('page', 'p-9')));
        self::assertFalse($a->admitsTypeAndId(new ResourceRef('site', 'p-1')));
        self::assertTrue(new Selector('page')->admitsTypeAndId(new ResourceRef('page', 'any')));
        self::assertSame([['attribute' => 'a', 'is' => 'z'], ['attribute' => 'a', 'is' => 'é']], new Selector('page', [new AttributeIs('a', 'é'), new AttributeIs('a', 'z')])->toArray()['atoms'], 'Ordered by the text itself, not escaped.');

        foreach ([new AttributeIs('n', 1), new AttributeIs('b', true), new RelatedTo('editor', new ResourceRef('principal', 'ana'))] as $atom) {
            self::assertEquals(new Selector('page', [$atom]), Selector::fromArray(new Selector('page', [$atom])->toArray()));
        }
    }

    public function testRefusesWhatIsntOne(): void
    {
        foreach ([
            static fn (): Selector => new Selector(''),
            static fn (): Selector => new Selector('page', [new IdIn(['a']), new IdIn(['b'])]),
            static fn (): Selector => new Selector('page', [new AttributeIs("st\xff", 'x')]),
            static fn (): IdIn => new IdIn([]),
            static fn (): IdIn => new IdIn(['']),
        ] as $i => $make) {
            try {
                $make();
                self::fail('Accepted case '.$i.'.');
            } catch (IntegratorError $e) {
                self::assertSame('concepts/delegation.md#resources', $e->docs);
            }
        }

        $conditions = [['id_in' => ['a', 1]], ['nothing' => 1], ['attribute' => 1, 'is' => 'x'], ['attribute' => 'a', 'is' => 1.5], ['attribute' => 'a', 'is' => null], ['to' => ['a', 'b']], ['related' => 1, 'to' => ['a', 'b']], ['related' => 'x', 'to' => 'ab'], ['related' => 'x', 'to' => [1, 'b']], ['related' => 'x', 'to' => ['a', 1]]];

        foreach ([...array_map(static fn (array $condition): array => ['type' => 'page', 'atoms' => [$condition]], $conditions), ['type' => 1, 'atoms' => []], ['type' => 'page', 'atoms' => 'x']] as $i => $stored) {
            try {
                Selector::fromArray($stored);
                self::fail('Read stored case '.$i.'.');
            } catch (\UnexpectedValueException $e) {
                self::assertStringStartsWith('A stored selector', $e->getMessage());
            }
        }
    }
}
