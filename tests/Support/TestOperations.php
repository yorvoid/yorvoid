<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpSelfService;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\BuiltInAuthorization;
use YorvoID\Core\Operation\NoAbilityPolicy;
use YorvoID\Core\Operation\NoSelfService;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;

/**
 * Operations over the handlers a test built, deciding as YorvoID does: the operator from a console, job
 * or system origin; someone's own account with the step-up policy, given StepUp; abilities as the policy
 * says.
 */
final class TestOperations
{
    public static function of(SecurityLog $log, ?StepUp $stepUp, OperationHandler ...$handlers): Operations
    {
        return self::with($log, new NoAbilityPolicy(), $stepUp, ...$handlers);
    }

    public static function with(SecurityLog $log, AbilityPolicy $abilities, ?StepUp $stepUp, OperationHandler ...$handlers): Operations
    {
        $authorization = new BuiltInAuthorization($abilities, null === $stepUp ? new NoSelfService() : new StepUpSelfService($stepUp));

        return new Operations(OperationCatalogue::of($handlers), $authorization, $log, $handlers);
    }
}
