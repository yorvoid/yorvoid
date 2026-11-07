<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;

/**
 * Handles the badge operations, keeping the permits it was given.
 */
final class Badges implements OperationHandler
{
    /** @var list<Permit> */
    public array $permits = [];

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

        $record = new RecordBadge($command->realm);

        return $this->record($record, $permit->consequence($record), $origin);
    }

    public function scan(ScanBadge $command, Permit $permit, Origin $origin): string
    {
        $permit->use($command);
        $this->permits[] = $permit;

        return 'scanned';
    }

    public function record(RecordBadge $command, Permit $permit, Origin $origin): string
    {
        $permit->use($command);
        $this->permits[] = $permit;

        return 'recorded';
    }
}
