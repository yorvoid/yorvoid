<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\IndeterminateReason;

/**
 * What a rule says about one request. "Can't tell" counts as a refusal that can't be ruled out: the
 * decision is Indeterminate, never a Permit (AUZ-007).
 *
 * @api
 */
final readonly class RuleAnswer
{
    /**
     * @param list<Ground> $grounds
     */
    private function __construct(
        public RuleOutcome $outcome,
        public string $detail,
        public ?IndeterminateReason $reason = null,
        public array $grounds = [],
    ) {
    }

    /**
     * @param Ground ...$grounds What it rests on besides the rule, e.g. the attributes it read (experimental)
     */
    public static function permit(string $detail, Ground ...$grounds): self
    {
        return new self(RuleOutcome::Permit, $detail, grounds: array_values($grounds));
    }

    /**
     * @param Ground ...$grounds What it rests on besides the rule, e.g. the attributes it read (experimental)
     */
    public static function forbid(string $detail, Ground ...$grounds): self
    {
        return new self(RuleOutcome::Forbid, $detail, grounds: array_values($grounds));
    }

    public static function abstain(): self
    {
        return new self(RuleOutcome::Abstain, 'The rule abstains.');
    }

    /**
     * @param Ground ...$grounds What it rests on besides the rule, e.g. the attribute it couldn't read (experimental)
     */
    public static function unknown(IndeterminateReason $reason, string $detail, Ground ...$grounds): self
    {
        return new self(RuleOutcome::Unknown, $detail, $reason, array_values($grounds));
    }
}
