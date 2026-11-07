<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\OperationCatalogue;

/**
 * A policy element that checks the operations it decides for when Operations is built, e.g. that every
 * ability they need is declared (PLT-004).
 *
 * @api
 */
interface ChecksCatalogue
{
    /**
     * @throws IntegratorError When an operation doesn't fit it
     */
    public function checkCatalogue(OperationCatalogue $catalogue): void;
}
