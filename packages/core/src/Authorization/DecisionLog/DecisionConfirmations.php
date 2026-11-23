<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use YorvoID\Core\Authorization\AttributeMacs;
use YorvoID\Core\Authorization\Confirmation;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Storage\Transactions;

/**
 * Whether a decision read an attribute with a value (M-9): what reconstructing a decision asks of its
 * record, which holds the value only where its resolver declared it public, and otherwise its MAC.
 *
 * Every confirmation is logged (yorvoid.decision.confirm, with the record, the attribute's name and the
 * result, never the value): repeated confirmations are how a value is guessed, so none goes unseen. It's
 * an administrator's API: give it only to code that reconstructs decisions for them. Limiting how often
 * someone may confirm is the caller's.
 *
 * @api
 */
final readonly class DecisionConfirmations
{
    public function __construct(
        private AttributeMacs $macs,
        private SecurityLog $log,
        private Transactions $transactions,
    ) {
    }

    /**
     * @param string|int|bool|list<string> $value
     * @param Actor                        $actor Who asks, for the log
     */
    public function confirm(DecisionRecord $record, string $name, string|int|bool|array $value, Actor $actor, Origin $origin): Confirmation
    {
        $result = $this->macs->confirm($record->realm, $name, $record->attributes[$name] ?? null, $value);

        $this->transactions->run(function () use ($record, $name, $result, $actor, $origin): void {
            $this->log->record($record->realm, 'yorvoid.decision.confirm', Outcome::Success, $origin, $actor->principalId, 'decision', $record->id, ['attribute' => $name, 'result' => $result->value]);
        });

        return $result;
    }
}
