<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use YorvoID\Core\Id\Id;
use YorvoID\Core\Id\UuidV7Generator;

/**
 * New IDs for tests, e.g. new PrincipalId(Ids::next()). YorvoID's services take an IdGenerator, the
 * only source of IDs in production: give them a UuidV7Generator with a clock of the test's to make its
 * IDs reproducible.
 *
 * @api
 */
final class Ids
{
    public static function next(): Id
    {
        return new UuidV7Generator()->next();
    }
}
