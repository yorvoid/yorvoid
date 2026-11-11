<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * What a step tells the person once its flow completes, e.g. "That recovery code is now used. You have
 * 9 left.": a message ID and its parameters, translated where the page is made. A flow has at most one:
 * a later step's replaces an earlier one's.
 *
 * @api
 */
final readonly class Notice
{
    /**
     * @param string                          $message    The message's ID, e.g. "yorvoid.flow.recovery_code.used"
     * @param array<string, string|int|float> $parameters The message's parameters
     */
    public function __construct(
        public string $message,
        public array $parameters = [],
    ) {
    }
}
