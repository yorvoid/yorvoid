<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use YorvoID\Tests\Support\Itf\Itf;
use YorvoID\Tests\Support\Itf\ItfState;
use YorvoID\Tests\Support\Itf\ItfVariant;
use YorvoID\Tests\Support\Itf\Replay;

/**
 * The replays' own tools: ITF read as the checker writes it, and a replay that fails where the code
 * and the model part.
 */
final class ItfTest extends TestCase
{
    public function testReadsTheCheckersValues(): void
    {
        self::assertSame(3, Itf::value(['#bigint' => '3']));
        self::assertSame(['a' => 1, 'b' => 2], Itf::value(['#map' => [['a', ['#bigint' => '1']], ['b', ['#bigint' => '2']]]]));
        self::assertSame([[['x', 1], true]], Itf::value(['#map' => [[['#tup' => ['x', ['#bigint' => '1']]], true]]]), 'A map whose keys aren\'t scalars: its pairs.');
        self::assertSame(['k1', 'k2'], Itf::value(['#set' => ['k1', 'k2']]));
        self::assertEquals(new ItfVariant('Valid', []), Itf::value(['tag' => 'Valid', 'value' => ['#tup' => []]]));
        self::assertSame(['key' => 'k1', 'effectiveAt' => 2], Itf::value(['key' => 'k1', 'effectiveAt' => ['#bigint' => '2']]), 'A record.');

        $file = tempnam(sys_get_temp_dir(), 'itf');
        file_put_contents($file, json_encode(['traces' => [[
            ['m::x::now' => ['#bigint' => '0'], 'mbt::actionTaken' => 'init', 'mbt::nondetPicks' => ['k' => ['tag' => 'None', 'value' => ['#tup' => []]]]],
            ['m::x::now' => ['#bigint' => '1'], 'mbt::actionTaken' => 'tick', 'mbt::nondetPicks' => ['k' => ['tag' => 'Some', 'value' => 'k1']]],
        ]]]));
        $traces = Itf::traces($file);
        unlink($file);

        self::assertSame('tick', $traces[0][1]->action);
        self::assertSame(1, $traces[0][1]->var('now'), 'Without its module\'s path.');
        self::assertSame('k1', $traces[0][1]->pick('k'));
        self::assertNull($traces[0][0]->pick('k'), 'Not chosen.');
    }

    public function testFailsWhereTheCodeAndTheModelPart(): void
    {
        $trace = [new ItfState('init', [], ['n' => 0]), new ItfState('tick', [], ['n' => 1]), new ItfState('tick', [], ['n' => 2])];
        $n = 0;
        $tick = static function () use (&$n): void {
            ++$n;
        };
        $code = static function () use (&$n): int {
            return $n;
        };

        self::assertSame(2, Replay::trace('Ticks', $trace, ['tick' => $tick], static fn (ItfState $state): mixed => $state->var('n'), $code));

        foreach ([
            'Ticks, step 2 (tick [])' => [['tick' => static function () use (&$n): void {
                $n = 0 === $n ? 1 : 7;
            }], 0],
            'Ticks, step 1 (tick []): the replay has nothing for the action "tick".' => [[], 0],
            'Ticks, initially' => [['tick' => $tick], 5],
        ] as $message => [$actions, $start]) {
            $n = $start;

            try {
                Replay::trace('Ticks', $trace, $actions, static fn (ItfState $state): mixed => $state->var('n'), $code);
                self::fail($message);
            } catch (AssertionFailedError $failure) {
                self::assertStringStartsWith($message, $failure->getMessage());
            }
        }
    }
}
