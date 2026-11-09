<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use PhpBench\Attributes as Bench;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Realm;

/**
 * An entry appended to the security log (SecurityLog::record()) on PostgreSQL: the realm's lock, the
 * chain's hash, the insert, in its own transaction.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Warmup(5)]
#[Bench\Iterations(200)]
#[Bench\Revs(1)]
final class LogBench
{
    private static ?SecurityLog $shared = null;

    private SecurityLog $log;

    public function setUp(): void
    {
        $this->log = self::$shared ??= self::log();
    }

    public function benchLogAppend(): void
    {
        $this->log->record(Realm::default(), 'acme.bench.append', Outcome::Success, new Origin(OriginKind::Web), details: ['page' => 'page:1']);
    }

    private static function log(): SecurityLog
    {
        $stack = Postgres::stack('log');

        return new SecurityLog($stack->log, $stack->transactions);
    }
}
