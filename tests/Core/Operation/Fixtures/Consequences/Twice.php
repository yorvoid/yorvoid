<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Consequences;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * An operation about a principal that causes two tokens for them, and so says so twice.
 *
 * @implements Command<null>
 */
#[Operation('acme.test.twice', 'Cause two tokens', AuthorizedBy::Operator, causes: ['yorvoid.token.issue', 'yorvoid.token.issue', 'yorvoid.session.end_all'])]
final readonly class Twice implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
