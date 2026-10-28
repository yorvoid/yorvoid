<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Secret;

/**
 * Where a flow got to: the step to show, or how it ended; and what the application does now, like
 * holding a session a step started.
 *
 * @api
 */
final readonly class Progress
{
    /**
     * @param string|null         $step         The step to show, while in progress
     * @param StepView|null       $view         What to show for it
     * @param list<Problem>       $problems     Why the last answer was refused
     * @param int|null            $retryAfter   Seconds to wait, after too many attempts
     * @param StartedSession|null $signedIn     A session a step started: hold it, so the person is signed in
     * @param Secret|null         $renewedToken The current session's new token: hold it in place of the old one
     * @param Problem|null        $failure      Why the flow couldn't go on, when it failed
     */
    public function __construct(
        public FlowStatus $status,
        public FlowState $state,
        public ?string $step = null,
        public ?StepView $view = null,
        public array $problems = [],
        public ?int $retryAfter = null,
        public ?StartedSession $signedIn = null,
        #[\SensitiveParameter]
        public ?Secret $renewedToken = null,
        public ?Problem $failure = null,
    ) {
    }
}
