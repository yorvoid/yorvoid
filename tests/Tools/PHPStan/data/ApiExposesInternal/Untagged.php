<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal;

/**
 * Untagged: not in the promise.
 */
final class Untagged
{
    public function give(Hidden $hidden): Hidden
    {
        return $hidden;
    }
}
