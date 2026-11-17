<?php

declare(strict_types=1);

namespace YorvoID\Fake;

/**
 * Stands for one of YorvoID's stores, installed in vendor/yorvoid.
 *
 * @api
 * @spi
 */
interface FakeStore
{
    public function write(): void;
}
