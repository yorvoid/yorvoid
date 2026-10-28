<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Event;

use YorvoID\Core\Log\LogEntry;

/**
 * A security log entry was committed: for the application's own audit log or a SIEM (AUD-010).
 *
 * @api
 */
final readonly class SecurityLogEntryRecorded
{
    public function __construct(
        public LogEntry $entry,
    ) {
    }
}
