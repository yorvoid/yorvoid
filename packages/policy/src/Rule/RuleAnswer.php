<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\IndeterminateReason;

/**
 * What a rule says about one request. "Can't tell" counts as a refusal that can't be ruled out: the
 * decision is Indeterminate, never a Permit (AUZ-007).
 *
 * @api
 */
final readonly class RuleAnswer
{
    private function __construct(
        public RuleOutcome $outcome,
        public string $detail,
        public ?IndeterminateReason $reason = null,
    ) {
    }

    public static function permit(string $detail): self
    {
        return new self(RuleOutcome::Permit, $detail);
    }

    public static function forbid(string $detail): self
    {
        return new self(RuleOutcome::Forbid, $detail);
    }

    public static function abstain(): self
    {
        return new self(RuleOutcome::Abstain, 'The rule abstains.');
    }

    public static function unknown(IndeterminateReason $reason, string $detail): self
    {
        return new self(RuleOutcome::Unknown, $detail, $reason);
    }
}
