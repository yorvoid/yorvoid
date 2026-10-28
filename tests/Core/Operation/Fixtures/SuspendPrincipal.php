<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Operation\Operation;

#[Operation('yorvoid.principal.suspend', 'Suspend a principal', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.activate')]
final readonly class SuspendPrincipal
{
    public function __construct(public string $principalId)
    {
    }
}
