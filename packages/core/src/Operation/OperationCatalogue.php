<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Every operation of the installed features, read once from the #[Operation] attributes of their command
 * classes. Mistakes (a command without the attribute, two operations with one name, an inverse that
 * doesn't exist) fail when the catalogue is built, i.e. when the application's container is.
 *
 * @api
 */
final readonly class OperationCatalogue
{
    /** @var array<string, OperationDescription> By name, sorted */
    private array $operations;

    /**
     * @param iterable<class-string> $commands
     */
    public function __construct(iterable $commands)
    {
        $operations = [];

        foreach ($commands as $command) {
            $description = self::describe($command);

            if (isset($operations[$description->name])) {
                throw new IntegratorError(\sprintf('The operation "%s" is declared by both %s and %s.', $description->name, $operations[$description->name]->command, $command), 'Give each command its own operation name.', 'concepts/operations-and-events.md#operations');
            }

            $operations[$description->name] = $description;
        }

        foreach ($operations as $operation) {
            if (null !== $operation->inverse && !isset($operations[$operation->inverse])) {
                throw new IntegratorError(\sprintf('The operation "%s" names "%s" as its inverse, which isn\'t in the catalogue.', $operation->name, $operation->inverse), 'Add the inverse operation\'s command to the catalogue, or remove the inverse.', 'concepts/operations-and-events.md#operations');
            }
        }

        ksort($operations);
        $this->operations = $operations;
    }

    /**
     * @return array<string, OperationDescription> By name, sorted
     */
    public function all(): array
    {
        return $this->operations;
    }

    public function has(string $name): bool
    {
        return isset($this->operations[$name]);
    }

    public function get(string $name): OperationDescription
    {
        return $this->operations[$name] ?? throw new IntegratorError(\sprintf('There is no operation "%s".', $name), 'Check the name against OperationCatalogue::all(); the feature that declares it may not be installed.', 'concepts/operations-and-events.md#operations');
    }

    /**
     * @param class-string $command
     */
    private static function describe(string $command): OperationDescription
    {
        $class = new \ReflectionClass($command);
        $attributes = $class->getAttributes(Operation::class);

        if ([] === $attributes) {
            throw new IntegratorError(\sprintf('The class %s has no #[Operation] attribute.', $command), 'Add #[Operation(name, description)] to the command class, or leave it out of the catalogue.', 'concepts/operations-and-events.md#operations');
        }

        $operation = $attributes[0]->newInstance();
        $parameters = [];

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $parameters[] = new Parameter(
                $parameter->getName(),
                null === $type ? 'mixed' : (string) $type,
                null === $type || $type->allowsNull(),
                $parameter->isOptional(),
                [] !== $parameter->getAttributes(\SensitiveParameter::class),
            );
        }

        return new OperationDescription($operation->name, $operation->description, $operation->ability, $operation->inverse, $command, $parameters);
    }
}
