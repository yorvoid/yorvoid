<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Event;

use YorvoID\Core\Realm;

/**
 * An audit record couldn't be written (AUD-006, AU-5): an alert, in every mode, for whoever must act on
 * it, e.g. paging the operator. It's dispatched whether or not what the record was for went ahead; a
 * listener that fails doesn't change that.
 *
 * @api
 */
final readonly class AuditFailed
{
    public const string SECURITY_LOG = 'security_log';

    public const string DECISION_LOG = 'decision_log';

    public const string VERIFIER_LOG = 'verifier_log';

    /** A checkpoint or an anchor of the security log (AUD-003) */
    public const string CHECKPOINTS = 'checkpoints';

    /**
     * @param string     $log     Which log: one of the constants
     * @param Realm|null $realm   The realm the records were for, when it's one
     * @param int        $records How many records were lost
     * @param bool       $refused Whether what they were for was refused, or rolled back, because of it
     */
    public function __construct(
        public string $log,
        public ?Realm $realm,
        public int $records,
        public bool $refused,
        public \Throwable $error,
    ) {
    }
}
