<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Operation;

#[Operation('yorvoid.session.end', 'End a session', ability: 'yorvoid.sessions.manage')]
final readonly class EndSession
{
    public function __construct(
        public string $sessionId,
        public ?string $reason = null,
        public ?Duration $holdFor = null,
        #[\SensitiveParameter]
        public string $confirmation = '',
        public int|string $untyped = 0,
        public mixed $anything = null,
    ) {
    }
}
