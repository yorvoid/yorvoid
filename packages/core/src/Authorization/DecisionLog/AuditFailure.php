<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

/**
 * What a decision does when its record can't be written (AUD-006, AU-5). Either way the audit alert is
 * raised ({@see \YorvoID\Core\Log\Event\AuditFailed}). The security log isn't concerned: an entry that
 * can't be written always fails the change it describes.
 *
 * @api
 */
enum AuditFailure: string
{
    /**
     * An operation's decision still has to be written before the operation runs; an ability's is
     * written at the end of the request, and what it allowed has run by then. The default.
     */
    case Continue = 'continue';

    /**
     * Every decision that's recorded is written before it's returned, and one whose record can't be
     * written can't permit: a Permit or Permit-offline becomes Indeterminate (audit_unavailable). A Deny
     * stays a Deny. Writes go out of the caller's transaction when the store has a connection of its
     * own, so a rollback doesn't take them back.
     */
    case Refuse = 'refuse';
}
