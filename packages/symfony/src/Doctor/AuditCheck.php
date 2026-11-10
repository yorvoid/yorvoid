<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Authorization\DecisionLog\AuditFailure;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;

/**
 * What a decision whose record can't be written does (AUD-006), and whether the configuration keeps
 * that promise: refusing needs records that a rollback of the application's can't take back, and on
 * record only what the decision log records.
 *
 * @internal
 */
final readonly class AuditCheck implements Check, Reports
{
    public function __construct(
        private AuditFailure $onFailure,
        private DecisionLogStore $store,
        private string $permits,
    ) {
    }

    public function summary(): string
    {
        return match ($this->onFailure) {
            AuditFailure::Continue => 'decisions continue when their record can\'t be written',
            AuditFailure::Refuse => 'decisions refuse when their record can\'t be written',
        };
    }

    public function findings(): iterable
    {
        if (AuditFailure::Refuse !== $this->onFailure) {
            return;
        }

        if ($this->store instanceof DbalDecisionLogStore && !$this->store->writesApart()) {
            yield Finding::warning('Decisions are written on the application\'s connection: a transaction of the application\'s that rolls back takes their records with it, after what they allowed may have happened.', 'Give the decision log a connection of its own to the same database: yorvoid.decision_log.connection.', 'concepts/security-log.md#when-a-record-cant-be-written');
        }

        if ('all' !== $this->permits) {
            yield Finding::warning('Decisions refuse when their record can\'t be written, but not every Permit of an ability is recorded, so those go ahead unrecorded.', 'Set yorvoid.decision_log.permits: all if every Permit must be on record.', 'concepts/security-log.md#when-a-record-cant-be-written');
        }
    }
}
