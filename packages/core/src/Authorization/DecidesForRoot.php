<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Whether a chain's root holds an ability now, on a resource or in the whole realm, decided as the
 * evaluator decides it for the root, with none of the root's evidence: what passing a delegation on
 * checks of its root (06 §5.3). A truth, never a decision a permit could be issued on.
 *
 * @api
 *
 * @experimental
 */
interface DecidesForRoot
{
    public function rootHolds(PrincipalId $root, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin): Truth;
}
