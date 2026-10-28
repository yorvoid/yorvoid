<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan\data;

use YorvoID\Core\Secret;

function compare(Secret $secret, ?Secret $maybe, string $guess): void
{
    $secret->reveal() === $guess;
    $guess == $secret->reveal();
    $secret->reveal() !== $guess;
    $secret->reveal() <=> $guess;
    strcmp($secret->reveal(), $guess);
    $maybe?->reveal() === $guess;

    // Fine: constant time, or not about the secret's value.
    $secret->equals($guess);
    hash_equals($secret->reveal(), $guess);
    \strlen($secret->reveal()) === 32;
    $guess === 'x';
    $compare = strcmp(...);
}
