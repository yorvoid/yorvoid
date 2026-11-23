<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;

/**
 * Validates a delegation's chain at a use (06 §5.3), for the evaluator: its scope when every link holds
 * now, or why not, as a truth that fails (a known failure: revoked, expired, outside its parent) or is
 * unknown (a link missing or inconsistent). yorvoid/delegation implements it.
 *
 * @spi
 *
 * @experimental
 */
interface ValidatesChains
{
    public function validate(DelegationId $leaf, PrincipalId $delegate, Request $request): DelegatedScope|Truth;
}
