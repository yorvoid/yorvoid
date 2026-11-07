<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * The value of a policy element's condition, or of an actor's authority, with what it rests on.
 *
 * @api
 */
final readonly class Truth
{
    /**
     * @param string       $detail   For the log and for developers, e.g. "The actor holds "acme.badge.issue"."
     * @param list<Remedy> $remedies What could make a failing permit hold
     */
    private function __construct(
        public TruthValue $value,
        public string $detail,
        public ?IndeterminateReason $reason = null,
        public array $remedies = [],
    ) {
    }

    public static function holds(string $detail): self
    {
        return new self(TruthValue::Holds, $detail);
    }

    /**
     * @param Remedy ...$remedies What could satisfy it, when only evidence is missing, e.g. a step-up
     */
    public static function fails(string $detail, Remedy ...$remedies): self
    {
        return new self(TruthValue::Fails, $detail, remedies: array_values($remedies));
    }

    public static function unknown(IndeterminateReason $reason, string $detail): self
    {
        return new self(TruthValue::Unknown, $detail, $reason);
    }
}
