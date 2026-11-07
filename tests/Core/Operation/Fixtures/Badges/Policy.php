<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;

/**
 * Answers every question the same way, and remembers it was asked.
 */
final class Policy implements AbilityPolicy
{
    public int $asked = 0;

    public function __construct(
        private readonly ?bool $answer,
    ) {
    }

    public function holds(Actor $actor, string $ability, Command $command): ?bool
    {
        ++$this->asked;

        return $this->answer;
    }
}
