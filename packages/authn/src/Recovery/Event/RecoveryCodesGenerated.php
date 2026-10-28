<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal got new recovery codes, and the old ones stopped working; it committed.
 *
 * @api
 */
final readonly class RecoveryCodesGenerated
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public int $count,
        public Origin $origin,
    ) {
    }
}
