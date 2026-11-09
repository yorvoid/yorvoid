<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Authorization\WithinOfflineLimit;
use YorvoID\Core\Clock\Instant;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\MockElapsed;
use YorvoID\Tests\Core\Authorization\Fixtures\StubOfflineDecider;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\OpenDoor;
use YorvoID\Tests\Support\Itf\Itf;
use YorvoID\Tests\Support\Itf\ItfState;
use YorvoID\Tests\Support\Itf\Replay;

/**
 * Replays executions of the formal model of an offline limit (spec/offline/elapsed.qnt, elapsedLimit)
 * against WithinOfflineLimit: the wall clock set back and forward (a MockClock), the elapsed time and
 * reboots (MockElapsed); after each step the clocks, and the last decision, are the model's. One tick of
 * the model is a minute; its limit is 3.
 */
final class OfflineLimitTracesTest extends TestCase
{
    private const string TRACES = __DIR__.'/../../../spec/offline/traces.itf.json';

    public function testTheLimitBehavesAsTheModel(): void
    {
        $operation = new OperationDescription('acme.door.open', 'Open a door', [AuthorizedBy::Ability], 'acme.doors.open', null, OpenDoor::class, [], [], true);
        $request = Request::forOperation($operation, new OpenDoor(new Realm('acme')), new Member(new Realm('acme'), new PrincipalId(new UuidV7())), new Origin(OriginKind::Web), new \DateTimeImmutable());
        $steps = 0;

        foreach (Itf::traces(self::TRACES) as $number => $trace) {
            $start = new \DateTimeImmutable('2026-11-09 18:00:00 UTC');
            $clock = new MockClock($start);
            $elapsed = new MockElapsed();
            $mark = null;
            $permitted = false;
            $limit = new WithinOfflineLimit(new StubOfflineDecider(Truth::holds('The decider would allow it.')), $elapsed, static function () use (&$mark): ?Instant {
                return $mark;
            }, Duration::minutes(3));
            $minutes = static fn (ItfState $state): int => self::int($state->pick('d'));

            $steps += Replay::trace('Execution '.$number, $trace, [
                'tick' => static function () use ($clock, $elapsed): void {
                    $clock->sleep(60);
                    $elapsed->advance(Duration::minutes(1));
                },
                'wallBack' => static function (ItfState $state) use ($clock, $minutes): void {
                    $clock->modify(\sprintf('-%d minutes', $minutes($state)));
                },
                'wallForward' => static function (ItfState $state) use ($clock, $minutes): void {
                    $clock->modify(\sprintf('+%d minutes', $minutes($state)));
                },
                'reboot' => static function () use ($elapsed): void {
                    $elapsed->reboot();
                },
                'loseLink' => static function () use (&$mark, $elapsed): void {
                    $mark = $elapsed->mark();
                },
                'decide' => static function () use (&$permitted, $limit, $request): void {
                    $permitted = TruthValue::Holds === $limit->decide($request)->value;
                },
            ], static fn (ItfState $state): array => [
                'wall' => self::int($state->var('wall')),
                'elapsed' => self::int($state->var('elapsed')),
                'permitted' => $state->var('permitted'),
            ], static function () use ($clock, $start, $elapsed, &$permitted): array {
                return [
                    'wall' => 100 + intdiv($clock->now()->getTimestamp() - $start->getTimestamp(), 60),
                    'elapsed' => intdiv($elapsed->mark()->nanoseconds, 60_000_000_000),
                    'permitted' => $permitted,
                ];
            });
        }

        self::assertGreaterThan(1000, $steps, 'Too few steps were replayed to mean much.');
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \LogicException('Not a number of the model\'s.');
    }
}
