<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\Request;

/**
 * A rule in PHP (AUZ-006, 007) that permits, forbids or abstains, or can't tell: a refusal wins over
 * everything, super roles included; a permit lets a request needing an ability through without a role.
 * Give it to the evaluator through RuleElements::of(). In Symfony, a service implementing it is added
 * by itself.
 *
 * @api
 */
interface Rule
{
    /** Stable, for reasons and the log, e.g. "acme.locked_pages" */
    public function name(): string;

    /** Whether it's about this request at all */
    public function matches(Request $request): bool;

    public function decide(Request $request): RuleAnswer;
}
