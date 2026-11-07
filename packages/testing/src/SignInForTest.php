<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Signs a principal in as if they'd used the method: for Scenario only, which runs it through
 * Operations of its own, never the application's.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession>
 *
 * @internal
 */
#[Operation('yorvoid.testing.sign_in', 'Sign a principal in, in a test', AuthorizedBy::Authentication, causes: ['yorvoid.session.start'])]
final readonly class SignInForTest implements Command
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public AuthenticationMethod $method,
    ) {
    }
}
