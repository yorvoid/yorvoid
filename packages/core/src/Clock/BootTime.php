<?php

declare(strict_types=1);

namespace YorvoID\Core\Clock;

/**
 * Elapsed time since the machine booted, suspended time included: on Linux, the first field of
 * /proc/uptime (CLOCK_BOOTTIME, to a hundredth of a second), with the boot's ID. PHP's hrtime() isn't
 * used: it reads CLOCK_MONOTONIC (CLOCK_UPTIME_RAW on macOS), which stops while the machine is
 * suspended, and a suspended node would then not count that time against its limit.
 *
 * Where /proc isn't there, marks are per process, from hrtime(), and for development only: they can't be
 * measured from in another process, e.g. another PHP-FPM worker, and don't count suspended time.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's offline decisions use it: it may change
 *               in a minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class BootTime implements Elapsed
{
    private string $process;

    /**
     * @param string $uptime The file whose first field is the seconds since boot
     * @param string $bootId The file with the boot's ID
     */
    public function __construct(
        private string $uptime = '/proc/uptime',
        private string $bootId = '/proc/sys/kernel/random/boot_id',
    ) {
        $this->process = 'process-'.bin2hex(random_bytes(8));
    }

    public function mark(): Instant
    {
        return $this->now();
    }

    public function since(Instant $mark): ?int
    {
        $now = $this->now();

        if ($now->clock !== $mark->clock || $mark->nanoseconds > $now->nanoseconds) {
            return null;
        }

        return $now->nanoseconds - $mark->nanoseconds;
    }

    /**
     * Whether marks count suspended time and can be measured from in another process: the boot's clock
     * was read, not the process's.
     */
    public function boots(): bool
    {
        return !str_starts_with($this->now()->clock, 'process-');
    }

    private function now(): Instant
    {
        $uptime = @file_get_contents($this->uptime);
        $boot = @file_get_contents($this->bootId);
        $boot = \is_string($boot) ? trim($boot) : '';

        if (\is_string($uptime) && 1 === preg_match('/^(?<seconds>\d+)\.(?<fraction>\d{1,9})\s/', $uptime, $parts) && 1 === preg_match('/^[0-9a-f-]{36}$/', $boot)) {
            return new Instant($boot, (int) $parts['seconds'] * 1_000_000_000 + (int) str_pad($parts['fraction'], 9, '0'));
        }

        return new Instant($this->process, hrtime(true));
    }
}
