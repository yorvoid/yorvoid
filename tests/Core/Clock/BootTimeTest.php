<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Clock;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Clock\BootTime;
use YorvoID\Core\Clock\Instant;

/**
 * Elapsed time since boot (XP-14): suspended time counted, as /proc/uptime has it, never a guess.
 */
final class BootTimeTest extends TestCase
{
    private const string BOOT = '3c1f8b2e-5d6a-4e7f-9a0b-1c2d3e4f5a6b';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-boot-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        mkdir($this->directory);
        file_put_contents($this->directory.'/boot_id', self::BOOT."\n");
    }

    protected function tearDown(): void
    {
        foreach (['uptime', 'boot_id'] as $file) {
            @unlink($this->directory.'/'.$file);
        }

        rmdir($this->directory);
    }

    public function testMeasuresFromTheBootsClock(): void
    {
        $time = $this->bootTime('100.25 50.00');
        $mark = $time->mark();

        self::assertSame([self::BOOT, 100_250_000_000], [$mark->clock, $mark->nanoseconds]);
        self::assertTrue($time->boots());
        self::assertSame(0, $time->since($mark));

        $this->uptime('3700.99 12.00');
        self::assertSame(3_600_740_000_000, $time->since($mark), 'An hour and 0.74 seconds, in nanoseconds.');
        self::assertNull($time->since(new Instant('another-boot', 1)), 'Another boot.');
        self::assertNull($time->since(new Instant(self::BOOT, 3_800_000_000_000)), 'Ahead of now: never a negative duration.');
        $this->uptime('7.1 1.00');
        self::assertSame(7_100_000_000, $time->mark()->nanoseconds, 'A tenth written as one digit.');
    }

    public function testWithoutTheBootsClockMarksArePerProcess(): void
    {
        foreach ([null, 'not a number', '12 5.00', '-1.5 2.00'] as $uptime) {
            $time = $this->bootTime($uptime);
            $mark = $time->mark();

            self::assertFalse($time->boots(), (string) $uptime);
            self::assertStringStartsWith('process-', $mark->clock);
            self::assertGreaterThanOrEqual(0, $time->since($mark), 'Within the process.');
            self::assertNull($this->bootTime($uptime)->since($mark), 'Not in another one, e.g. another PHP-FPM worker.');
        }

        file_put_contents($this->directory.'/boot_id', 'not an ID');
        self::assertFalse($this->bootTime('100.25 50.00')->boots(), 'A boot ID that isn\'t one.');
    }

    /**
     * The default reads Linux's boot time, which counts suspended time; hrtime() doesn't (CLOCK_MONOTONIC).
     */
    public function testTheDefaultReadsLinuxsBootTime(): void
    {
        if (!is_readable('/proc/uptime')) {
            self::markTestSkipped('No /proc/uptime here.');
        }

        $time = new BootTime();
        $mark = $time->mark();
        $uptime = (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0];

        self::assertTrue($time->boots());
        self::assertSame(trim((string) file_get_contents('/proc/sys/kernel/random/boot_id')), $mark->clock);
        self::assertEqualsWithDelta($uptime, $mark->nanoseconds / 1e9, 1.0);
    }

    public function testAMarkIsReadBackAsWritten(): void
    {
        $mark = new Instant(self::BOOT, 100_250_000_000);

        self::assertEquals($mark, Instant::fromString((string) $mark));
        self::assertEquals(new Instant('process-1', 0), Instant::fromString('process-1:0'));

        foreach (['', self::BOOT, self::BOOT.':', self::BOOT.':-1', self::BOOT.':012', self::BOOT.':1.5', self::BOOT.':1234567890123456789', 'a b:1', str_repeat('a', 65).':1', self::BOOT.":1\n"] as $stored) {
            self::assertNull(Instant::fromString($stored), $stored);
        }
    }

    private function bootTime(?string $uptime): BootTime
    {
        $this->uptime($uptime);

        return new BootTime($this->directory.'/uptime', $this->directory.'/boot_id');
    }

    private function uptime(?string $uptime): void
    {
        null === $uptime ? @unlink($this->directory.'/uptime') : file_put_contents($this->directory.'/uptime', $uptime."\n");
    }
}
