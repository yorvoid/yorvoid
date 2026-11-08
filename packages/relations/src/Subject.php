<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalId;

/**
 * Who a relation is to: one resource, e.g. principal:<id>, or everyone with a relation to one, e.g.
 * group:editors#member (a subject set).
 *
 * @api
 */
final readonly class Subject implements \Stringable
{
    /**
     * @param string|null $relation For a subject set: the relation its members have to the resource
     *
     * @throws IntegratorError When the relation isn't a relation's name
     */
    public function __construct(
        public ResourceRef $resource,
        public ?string $relation = null,
    ) {
        if (null !== $relation) {
            RelationDefinition::check($relation);
        }
    }

    public static function principal(PrincipalId $id): self
    {
        return new self(new ResourceRef('principal', $id->uuid->toRfc4122()));
    }

    /**
     * From "type:id" or "type:id#relation", as __toString() writes it.
     *
     * @throws IntegratorError
     */
    public static function parse(string $subject): self
    {
        $parts = explode('#', $subject, 2);

        return new self(ResourceRef::parse($parts[0]), $parts[1] ?? null);
    }

    public function equals(self $other): bool
    {
        return $this->resource->equals($other->resource) && $this->relation === $other->relation;
    }

    public function __toString(): string
    {
        return null === $this->relation ? (string) $this->resource : $this->resource.'#'.$this->relation;
    }
}
