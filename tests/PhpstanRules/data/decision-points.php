<?php

declare(strict_types=1);

namespace App;

use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;

final readonly class Composition
{
    public function operations(OperationCatalogue $catalogue, Authorization $authorization, SecurityLog $log): Operations
    {
        return new Operations($catalogue, $authorization, $log, []);
    }

    public function fullyQualified(OperationCatalogue $catalogue, Authorization $authorization, SecurityLog $log): Operations
    {
        return new \YorvoID\Core\Operation\Operations($catalogue, $authorization, $log, []);
    }

    public function somethingElse(): \ArrayObject
    {
        return new \ArrayObject();
    }
}
