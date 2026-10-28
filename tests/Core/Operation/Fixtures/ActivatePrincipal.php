<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Operation\Operation;

#[Operation('yorvoid.principal.activate', 'Activate a principal', inverse: 'yorvoid.principal.suspend')]
final readonly class ActivatePrincipal
{
    public function __construct(public string $principalId)
    {
    }
}
