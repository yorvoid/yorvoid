<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Someone recovered a principal's account with a recovery link an operator issued, and it committed.
 * Tell the principal on every verified channel (ATN-029): if it wasn't them, they need to know now.
 *
 * @api
 */
final readonly class RecoveryLinkUsed
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public Origin $origin,
    ) {
    }
}
