<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\OperationDescription;

/**
 * What a decision is about: one operation, with its command, asked by an actor from an origin, at one
 * moment that every element of the decision reads (P7).
 *
 * @api
 */
final readonly class Request
{
    /**
     * @param Command<mixed> $command
     */
    public function __construct(
        public OperationDescription $operation,
        public Command $command,
        public Actor $actor,
        public Origin $origin,
        public \DateTimeImmutable $at,
    ) {
    }

    /**
     * Whether the actor acts in the command's realm as a principal of it.
     */
    public function actorInRealm(): bool
    {
        return null !== $this->actor->principalId && null !== $this->actor->realm && $this->actor->realm->equals($this->command->realm);
    }
}
