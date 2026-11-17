<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Someone recovered a principal's account with one of its recovery codes, and it committed. Tell the
 * principal on every verified channel (ATN-025): if it wasn't them, they need to know now.
 *
 * @api
 */
final readonly class RecoveryCodeUsed
{
    /**
     * @param int $remaining How many codes the principal has left
     *
     * @internal YorvoID dispatches it: its listeners act on it, so an application doesn't make one
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public int $remaining,
        public Origin $origin,
    ) {
    }
}
