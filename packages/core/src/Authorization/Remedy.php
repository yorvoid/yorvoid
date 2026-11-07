<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What could satisfy an unmet requirement of a permit that otherwise applies, e.g. a recent step-up
 * (06 §3). Satisfying it creates evidence; the next request is a new decision, never this one granted.
 *
 * @api
 */
interface Remedy
{
    /** E.g. "step_up", as in AuthZEN ARAP */
    public function name(): string;

    /**
     * @return array<string, scalar|null> What it asks for, e.g. ["max_age" => 300]
     */
    public function parameters(): array;
}
