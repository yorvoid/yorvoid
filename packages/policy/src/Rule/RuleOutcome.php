<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

/**
 * @api
 */
enum RuleOutcome: string
{
    case Permit = 'permit';
    case Forbid = 'forbid';
    case Abstain = 'abstain';
    case Unknown = 'unknown';
}
