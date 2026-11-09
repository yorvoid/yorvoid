<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Every declared resource type and relation, checked when YorvoID is built (PLT-004): a relation
 * implied by, or inherited from, one that isn't declared fails.
 *
 * @api
 */
final readonly class RelationSchema
{
    /**
     * @param array<string, ResourceType> $types By name
     */
    private function __construct(
        private array $types,
    ) {
    }

    /**
     * @param iterable<RelationDeclarations> $declarations
     *
     * @throws IntegratorError
     */
    public static function of(iterable $declarations): self
    {
        $types = [];

        foreach ($declarations as $declaration) {
            foreach ($declaration->types() as $type) {
                if (isset($types[$type->name])) {
                    throw new IntegratorError(\sprintf('The resource type "%s" is declared twice.', $type->name), 'Declare each type once, with all its relations.', 'concepts/authorization.md#relations');
                }

                $types[$type->name] = $type;
            }
        }

        foreach ($types as $type) {
            foreach ($type->relations as $relation) {
                foreach ([...$relation->impliedBy, ...array_keys($relation->fromParents)] as $other) {
                    if (!isset($type->relations[$other])) {
                        throw new IntegratorError(\sprintf('The relation "%s" of "%s" refers to "%s", which "%s" doesn\'t declare.', $relation->name, $type->name, $other, $type->name), 'Declare it, or refer to a relation the type has.', 'concepts/authorization.md#relations');
                    }
                }
            }
        }

        return new self($types);
    }

    /**
     * Every resource type, by name.
     *
     * @return array<string, ResourceType>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function type(string $name): ?ResourceType
    {
        return $this->types[$name] ?? null;
    }

    public function relation(string $type, string $relation): ?RelationDefinition
    {
        return $this->types[$type]->relations[$relation] ?? null;
    }

    /**
     * The relations of the type that give the ability on a resource of it.
     *
     * @return list<string>
     */
    public function granting(string $type, string $ability): array
    {
        $relations = [];

        foreach ($this->types[$type]->relations ?? [] as $relation) {
            if (\in_array($ability, $relation->grants, true)) {
                $relations[] = $relation->name;
            }
        }

        return $relations;
    }

    /**
     * The abilities a relation gives on a resource of the type, with those of the relations it implies:
     * an editor holds what a viewer holds.
     *
     * @return list<string> Each once
     */
    public function abilitiesGivenBy(string $type, string $relation): array
    {
        $abilities = [];
        $pending = [$relation];
        $seen = [];

        while ([] !== $pending) {
            $name = array_shift($pending);
            $definition = $this->relation($type, $name);

            if (isset($seen[$name]) || null === $definition) {
                continue;
            }

            $seen[$name] = $definition;
            array_push($abilities, ...$definition->grants);

            foreach ($this->types[$type]->relations ?? [] as $other) {
                if (\in_array($name, $other->impliedBy, true)) {
                    $pending[] = $other->name;
                }
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * @return list<string> Every ability some relation gives, each once
     */
    public function abilities(): array
    {
        $abilities = [];

        foreach ($this->types as $type) {
            foreach ($type->relations as $relation) {
                array_push($abilities, ...$relation->grants);
            }
        }

        return array_values(array_unique($abilities));
    }
}
