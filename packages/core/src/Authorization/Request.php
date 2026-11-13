<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsNoResource;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Realm;

/**
 * What a decision is about, asked by an actor from an origin, at one moment that every element of the
 * decision reads (P7): either an operation with its command, or an ability on a resource that isn't an
 * operation of YorvoID's, e.g. an application's "edit this page". An ability's request may carry its
 * subject, the application's own object (the page), for rules and attribute resolvers to read: it's
 * never recorded, logged or part of a cache key, and nothing keeps it beyond the request.
 *
 * @api
 */
final readonly class Request
{
    /**
     * @param string|null               $ability   The ability it needs: the operation's, or the one asked about
     * @param OperationDescription|null $operation Null when it's about an ability only
     * @param Command<mixed>|null       $command   The operation's command
     * @param object|null               $subject   The application's own object it's about, e.g. the page
     */
    private function __construct(
        private string $name,
        public Realm $realm,
        public ?string $ability,
        public ?ResourceRef $resource,
        public ?OperationDescription $operation,
        public ?Command $command,
        public Actor $actor,
        public Origin $origin,
        public \DateTimeImmutable $at,
        public ?object $subject = null,
    ) {
    }

    /**
     * @param Command<mixed> $command
     */
    public static function forOperation(OperationDescription $operation, Command $command, Actor $actor, Origin $origin, \DateTimeImmutable $at): self
    {
        return new self($operation->name, $command->realm, $operation->ability, $command instanceof ConcernsResource ? $command->resource() : null, $operation, $command, $actor, $origin, $at);
    }

    /**
     * The resource is the one given, or else the one the subject concerns. A subject with neither is
     * about no resource: only roles on the whole realm count for it.
     *
     * @param object|null $subject The application's own object it's about, e.g. the page
     *
     * @throws IntegratorError When the subject concerns another resource than the one given, or is a ResourceRef
     */
    public static function forAbility(string $ability, Realm $realm, ?ResourceRef $resource, Actor $actor, Origin $origin, \DateTimeImmutable $at, ?object $subject = null): self
    {
        if ($subject instanceof ResourceRef) {
            throw new IntegratorError(\sprintf('The subject of "%s" is the resource %s.', $ability, $subject), 'Give a ResourceRef as the resource; the subject is the application\'s own object.', 'concepts/authorization.md#the-subject');
        }

        if ($subject instanceof ConcernsNoResource && ($subject instanceof ConcernsResource || null !== $resource)) {
            throw new IntegratorError(\sprintf('The subject of "%s" concerns no resource, and is given %s.', $ability, $subject instanceof ConcernsResource ? 'one of its own' : 'one'), 'Give a subject that concerns no resource without one, or have it say which it concerns with ConcernsResource alone.', 'concepts/authorization.md#the-subject');
        }

        if ($subject instanceof ConcernsResource) {
            $concerns = $subject->resource();

            if (null !== $resource && !$resource->equals($concerns)) {
                throw new IntegratorError(\sprintf('The subject of "%s" concerns %s, not %s.', $ability, $concerns, $resource), 'Give the resource the subject concerns, or none.', 'concepts/authorization.md#the-subject');
            }

            $resource = $concerns;
        }

        return new self($ability, $realm, $ability, $resource, null, null, $actor, $origin, $at, $subject);
    }

    /**
     * The same request about another resource: to weigh what a change does to a resource it isn't
     * about, as no-escalation does.
     */
    public function on(?ResourceRef $resource): self
    {
        return new self($this->name, $this->realm, $this->ability, $resource, $this->operation, $this->command, $this->actor, $this->origin, $this->at, $this->subject);
    }

    /**
     * The same request, asked by another actor: each side of a view-as.
     */
    public function by(Actor $actor): self
    {
        return new self($this->name, $this->realm, $this->ability, $this->resource, $this->operation, $this->command, $actor, $this->origin, $this->at, $this->subject);
    }

    /**
     * What it's about, for reasons and the log: the operation's name, or the ability's.
     */
    public function name(): string
    {
        return $this->name;
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
