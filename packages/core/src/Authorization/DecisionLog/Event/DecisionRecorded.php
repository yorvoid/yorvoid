<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog\Event;

use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;

/**
 * A decision record was written: an operation's decision, an ability's that was recorded, a refusal or
 * a failed attempt (D-42). For a SIEM, or the application's own audit: refusals and failed attempts
 * aren't entries of the security log, so SecurityLogEntryRecorded doesn't carry them.
 *
 * Dispatched once the record is written: a refusal or an attempt waits for the end of the caller's
 * transaction, so its event may come after the response was sent.
 *
 * @api
 */
final readonly class DecisionRecorded
{
    /**
     * @internal YorvoID dispatches it
     */
    public function __construct(
        public DecisionRecord $record,
    ) {
    }
}
