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

    /** @var array<class-string, OperationDescription> */
    private array $byCommand;

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
        $this->byCommand = array_column(array_map(static fn (OperationDescription $operation): array => [$operation->command, $operation], $operations), 1, 0);
    }

    /**
     * The catalogue of the operations these handlers run.
     *
     * @param iterable<OperationHandler> $handlers
     */
    public static function of(iterable $handlers): self
    {
        $commands = [];

        foreach ($handlers as $handler) {
            $commands = [...$commands, ...array_keys($handler->operations())];
        }

        return new self($commands);
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
     * The operation a command class runs.
     *
     * @param class-string $command
     */
    public function forCommand(string $command): OperationDescription
    {
        return $this->byCommand[$command] ?? throw new IntegratorError(\sprintf('The command %s isn\'t in the operation catalogue.', $command), 'Add its class to the catalogue\'s commands.', 'concepts/operations-and-events.md#operations');
    }

    /**
     * @param class-string $command
     */
    private static function describe(string $command): OperationDescription
    {
        $class = new \ReflectionClass($command);
        $operation = Operation::of($command);

        if (!$class->implementsInterface(Command::class)) {
            throw new IntegratorError(\sprintf('The class %s has #[Operation] but isn\'t a Command.', $command), 'Implement YorvoID\\Core\\Operation\\Command.', 'concepts/operations-and-events.md#operations');
        }

        if (\in_array(AuthorizedBy::Self, $operation->authorizedBy, true) && !$class->implementsInterface(SelfOperation::class)) {
            throw new IntegratorError(\sprintf('The operation "%s" may be run on one\'s own account, but %s doesn\'t say whose it concerns.', $operation->name, $command), 'Implement YorvoID\\Core\\Operation\\SelfOperation.', 'concepts/operations-and-events.md#operations');
        }

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

        return new OperationDescription($operation->name, $operation->description, $operation->authorizedBy, $operation->ability, $operation->inverse, $command, $parameters, $operation->causes, $operation->acceptsOffline);
    }
}
