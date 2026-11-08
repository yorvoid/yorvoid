<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Principal\PrincipalId;

/**
 * What an application's subjects tell its rules: who wrote them and how they're classified.
 */
interface Authored
{
    public function author(): PrincipalId;

    public function classification(): string;
}
