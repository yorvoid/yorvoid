<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;

/**
 * Badge operations that record what they do, as YorvoID's own handlers do; scanning fails after it
 * recorded its entry.
 */
final class LoggingBadges implements OperationHandler
{
    /** @var list<Permit> */
    public array $permits = [];

    /** @var (\Closure(Permit): void)|null Called as the operation starts */
    public ?\Closure $onRun = null;

    public function __construct(
        private readonly SecurityLog $log,
    ) {
    }

    public function operations(): array
    {
        return [
            IssueBadge::class => $this->issue(...),
            ScanBadge::class => $this->scan(...),
            RecordBadge::class => $this->record(...),
        ];
    }

    public function issue(IssueBadge $command, Permit $permit, Origin $origin): string
    {
        $permit->use($command);
        $this->permits[] = $permit;
        if (null !== $this->onRun) {
            ($this->onRun)($permit);
        }
        $this->log->record($command->realm, 'acme.badge.issue', Outcome::Success, $origin, $command->principalId);
        $record = new RecordBadge($command->realm);

        return $this->record($record, $permit->consequence($record), $origin);
    }

    public function scan(ScanBadge $command, Permit $permit, Origin $origin): never
    {
        $permit->use($command);
        $this->log->record($command->realm, 'acme.badge.scan', Outcome::Success, $origin);

        throw new \RuntimeException('The scanner jammed.');
    }

    public function record(RecordBadge $command, Permit $permit, Origin $origin): string
    {
        $permit->use($command);
        $this->log->record($command->realm, 'acme.badge.record', Outcome::Success, $origin, details: ['badge' => 'recorded']);

        return 'recorded';
    }
}
