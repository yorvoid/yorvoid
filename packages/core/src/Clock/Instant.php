<?php

declare(strict_types=1);

namespace YorvoID\Core\Clock;

/**
 * A mark of elapsed time: whose clock it's of (a boot, or a process) and how far it had run, in
 * nanoseconds. It can be stored and read back, e.g. with a grant, so that a mark survives a restart of
 * the process, though not of the machine.
 *
 * A stored mark is trusted data: whoever can rewrite it can extend a limit measured from it. Where it's
 * kept and how it's protected is the application's, e.g. with the grant, in its store.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's offline decisions use it: it may change
 *               in a minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class Instant implements \Stringable
{
    /** The clock's ID, and the nanoseconds as written: no leading zeros, small enough for an int */
    private const string FORMAT = '/^([A-Za-z0-9-]{1,64}):(0|[1-9]\d{0,17})$/D';

    /**
     * @param string $clock       The boot's ID, or the process's where marks are per process
     * @param int    $nanoseconds How far the clock had run, from its start
     */
    public function __construct(
        public string $clock,
        public int $nanoseconds,
    ) {
    }

    /**
     * A stored mark read back, or null when it isn't one.
     */
    public static function fromString(string $mark): ?self
    {
        return 1 === preg_match(self::FORMAT, $mark, $parts) ? new self($parts[1], (int) $parts[2]) : null;
    }

    public function __toString(): string
    {
        return $this->clock.':'.$this->nanoseconds;
    }
}
