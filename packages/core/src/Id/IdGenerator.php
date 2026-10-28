<?php

declare(strict_types=1);

namespace YorvoID\Core\Id;

use Symfony\Component\Uid\Uuid;

/**
 * Creates the IDs of YorvoID's records (PLT-026). The default creates UUIDv7 from the clock; tests and
 * scenario fixtures replace it to make IDs reproducible.
 *
 * @api
 */
interface IdGenerator
{
    public function next(): Uuid;
}
