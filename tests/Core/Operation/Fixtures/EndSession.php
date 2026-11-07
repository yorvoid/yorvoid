<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * @implements SelfOperation<null>
 */
#[Operation('yorvoid.session.end', 'End a session', [AuthorizedBy::Self, AuthorizedBy::Ability], ability: 'yorvoid.sessions.manage')]
final readonly class EndSession implements SelfOperation
{
    public function __construct(
        public Realm $realm,
        public string $sessionId,
        public ?string $reason = null,
        public ?Duration $holdFor = null,
        #[\SensitiveParameter]
        public string $confirmation = '',
        public int|string $untyped = 0,
        public mixed $anything = null,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return true;
    }
}
