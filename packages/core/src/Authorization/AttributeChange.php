<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Principal\PrincipalId;

/**
 * A security attribute set or removed on a principal: what it gives is the application's rules' that
 * read it, which can't be weighed.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise while delegation (M3) shapes it: it may change in a minor
 *               release ([compatibility](docs/compatibility.md)).
 */
final readonly class AttributeChange
{
    public function __construct(
        public PrincipalId $principal,
        public string $name,
        public bool $removes,
    ) {
    }
}
