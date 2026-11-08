<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Realm;

/**
 * What a decision is about, asked by an actor from an origin, at one moment that every element of the
 * decision reads (P7): either an operation with its command, or an ability on a resource that isn't an
 * operation of YorvoID's, e.g. an application's "edit this page".
 *
 * @api
 */
final readonly class Request
{
    /**
     * @param string|null               $ability   The ability it needs: the operation's, or the one asked about
     * @param OperationDescription|null $operation Null when it's about an ability only
     * @param Command<mixed>|null       $command   The operation's command
     */
    private function __construct(
        private string $subject,
        public Realm $realm,
        public ?string $ability,
        public ?ResourceRef $resource,
        public ?OperationDescription $operation,
        public ?Command $command,
        public Actor $actor,
        public Origin $origin,
        public \DateTimeImmutable $at,
    ) {
    }

    /**
     * @param Command<mixed> $command
     */
    public static function forOperation(OperationDescription $operation, Command $command, Actor $actor, Origin $origin, \DateTimeImmutable $at): self
    {
        return new self($operation->name, $command->realm, $operation->ability, $command instanceof ConcernsResource ? $command->resource() : null, $operation, $command, $actor, $origin, $at);
    }

    public static function forAbility(string $ability, Realm $realm, ?ResourceRef $resource, Actor $actor, Origin $origin, \DateTimeImmutable $at): self
    {
        return new self($ability, $realm, $ability, $resource, null, null, $actor, $origin, $at);
    }

    /**
     * What it's about, for reasons and the log: the operation's name, or the ability's.
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * The ways it may be authorized: the operation's, or only by an ability.
     *
     * @return non-empty-list<AuthorizedBy>
     */
    public function authorizedBy(): array
    {
        return $this->operation->authorizedBy ?? [AuthorizedBy::Ability];
    }

    /**
     * Whether the actor acts in the request's realm as a principal of it.
     */
    public function actorInRealm(): bool
    {
        return null !== $this->actor->principalId && null !== $this->actor->realm && $this->actor->realm->equals($this->realm);
    }
}
