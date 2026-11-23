<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Core\Authorization\DecidesForRoot;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Every root holds what it passes on: for tests about something else than the root's authority.
 */
final readonly class RootsHold implements DecidesForRoot
{
    public function rootHolds(PrincipalId $root, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin): Truth
    {
        return Truth::holds('The root holds it.');
    }
}
