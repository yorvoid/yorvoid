<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * Whether the application's clock is right: ceremonies, tokens, sessions and throttling expire by it.
 * The database's clock is the nearest second opinion; a server that disagrees with it by seconds has
 * stopped synchronising its time.
 *
 * @internal
 */
final readonly class ClockCheck implements Check
{
    public const int TOLERANCE_SECONDS = 5;

    /**
     * @param \Closure(): Database $database Built here, where not reaching it is the migrations check's finding
     */
    public function __construct(
        private ClockInterface $clock,
        private \Closure $database,
        private bool $debug,
    ) {
    }

    public function findings(): iterable
    {
        if (!$this->debug && is_a($this->clock, 'Symfony\Component\Clock\MockClock')) {
            yield Finding::problem('The application\'s clock is a MockClock outside development: it stands still, and nothing expires.', 'Leave the clock service to Symfony, or give it a clock that tells the time.', 'concepts/symfony.md#configuration');
        }

        try {
            $database = ($this->database)();
            $now = $database->connection->fetchOne(match ($database->platform) {
                SqlPlatform::PostgreSQL => 'SELECT EXTRACT(EPOCH FROM CLOCK_TIMESTAMP())',
                SqlPlatform::SQLite => 'SELECT (julianday(\'now\') - 2440587.5) * 86400.0',
            });
        } catch (DbalException) {
            // The migrations check reports a database it can't reach.
            return;
        }

        \assert(is_numeric($now));
        $drift = (float) $this->clock->now()->format('U.u') - (float) $now;

        if (abs($drift) > self::TOLERANCE_SECONDS) {
            yield Finding::problem(\sprintf('The application\'s clock is %d seconds %s the database\'s.', abs($drift), $drift > 0 ? 'ahead of' : 'behind'), 'Synchronise both servers\' time (NTP); tokens, ceremonies and sessions otherwise expire early or late.', 'concepts/symfony.md#configuration');
        }
    }
}
