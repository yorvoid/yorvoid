<?php

declare(strict_types=1);

namespace YorvoID\Fake;

/**
 * @api
 */
class FakeApi
{
    public string $open = '';

    /** @internal */
    public string $secret = '';

    /**
     * @internal The container builds it
     */
    public function __construct()
    {
    }
}
