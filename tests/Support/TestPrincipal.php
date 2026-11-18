<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal acting without a session, for tests of what the policy decides for them: what an
 * ActingPrincipal was before D-45, which only YorvoID makes now. TrustedActors proves it.
 */
final readonly class TestPrincipal implements Actor
{
    public function __construct(
        public ?Realm $realm,
        public ?PrincipalId $principalId,
    ) {
    }
}
