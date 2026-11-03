<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Thrown by a step's enter() when the flow can't go on, e.g. a step-up for someone with no passkey: the
 * flow fails with the problem, as when submit() gives StepResult::failed().
 *
 * @api
 */
final class StepFailed extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Problem $problem,
    ) {
        parent::__construct($problem->message);
    }
}
