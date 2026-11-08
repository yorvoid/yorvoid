<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Realm;

/**
 * A version of what decisions read, which every change to it increments: the policy's, the
 * relations'. Each decision records the versions it read (AUZ-009), and what's cached is kept
 * against them (AUZ-031).
 *
 * @api
 */
interface VersionSource
{
    /** What it versions, e.g. "policy": the key in a decision's versions */
    public function name(): string;

    /** 0 before the first change */
    public function version(Realm $realm): int;
}
