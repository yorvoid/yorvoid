<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal acting without a session, as an API token's would.
 */
final readonly class Member implements Actor
{
    public function __construct(
        public ?Realm $realm,
        public ?PrincipalId $principalId,
    ) {
    }
}
