<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal;

/**
 * @api
 */
final class Allowed
{
    /**
     * @internal The container builds it
     */
    public function __construct(private Hidden $hidden)
    {
    }

    public function shown(): Shown
    {
        return new Shown();
    }

    protected function sealed(Hidden $hidden): void
    {
    }
}
