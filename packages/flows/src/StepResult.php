<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Secret;

/**
 * What a step made of what the person sent: done, and the flow moves on; refused, and the step is
 * shown again; or the flow can't go on.
 *
 * @api
 */
final readonly class StepResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $forget
     * @param list<Problem>        $problems
     */
    private function __construct(
        public bool $done,
        public array $data = [],
        public array $forget = [],
        public ?StartedSession $signedIn = null,
        #[\SensitiveParameter]
        public ?Secret $renewedToken = null,
        public array $problems = [],
        public ?int $retryAfter = null,
        public ?Problem $failure = null,
    ) {
    }

    /**
     * The step is done.
     *
     * @param array<string, mixed> $data         What to keep in the flow's state
     * @param list<string>         $forget       What to remove from it, e.g. recovery codes once shown
     * @param StartedSession|null  $signedIn     The session the step started: the application holds it, and the next steps run in it
     * @param Secret|null          $renewedToken The current session's new token, e.g. after a step-up
     */
    public static function done(array $data = [], array $forget = [], ?StartedSession $signedIn = null, #[\SensitiveParameter] ?Secret $renewedToken = null): self
    {
        return new self(true, $data, $forget, $signedIn, $renewedToken);
    }

    /**
     * The step refused what was sent; it's prepared again, and shown with the problems.
     */
    public static function refused(Problem $problem, Problem ...$more): self
    {
        return new self(false, problems: [$problem, ...array_values($more)]);
    }

    /**
     * Too many attempts: the step is shown again, to try after a while.
     */
    public static function throttled(Problem $problem, int $retryAfter): self
    {
        return new self(false, problems: [$problem], retryAfter: $retryAfter);
    }

    /**
     * The flow can't go on, e.g. an invitation that doesn't work any more. It ends.
     */
    public static function failed(Problem $failure): self
    {
        return new self(false, failure: $failure);
    }
}
