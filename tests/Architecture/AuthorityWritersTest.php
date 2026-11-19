<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\DeclaredEffects;
use YorvoID\Core\Authorization\HoldsAuthority;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Policy\PolicyEffects;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Roles;

/**
 * A handler that can write who may do what runs only commands marked as changing authority, and each
 * of those has its effect declared (D-51): a new one, e.g. a delegation's, fails here until it's
 * marked and declared, so the forbids that weigh changes never take it for one that gives nothing.
 * It sees the stores a handler takes in its constructor: one it gets any other way, it doesn't.
 */
final class AuthorityWritersTest extends TestCase
{
    public function testEveryHandlerThatWritesAuthorityRunsOnlyDeclaredChanges(): void
    {
        $handlers = self::handlers(self::classes());
        self::assertNotEmpty($handlers);
        self::assertSame([], self::unmarked($handlers), 'These handlers can write authority, but run commands not marked ChangesAuthority.');

        $marked = [];

        foreach ($handlers as $handler) {
            array_push($marked, ...array_keys($handler->operations()));
        }

        // Every package's effects: the policy's, for now.
        new DeclaredEffects([new PolicyEffects(new \ReflectionClass(Roles::class)->newInstanceWithoutConstructor())])->check(new OperationCatalogue($marked));
    }

    public function testAHandlerThatWritesAuthorityThroughAnUnmarkedCommandIsFound(): void
    {
        self::assertSame([PlantedHandler::class => [PlantedCommand::class]], self::unmarked(self::handlers([PlantedHandler::class])));
    }

    /**
     * @param list<class-string> $classes
     *
     * @return array<class-string, OperationHandler> The handlers whose constructor takes a store of authority
     */
    private static function handlers(array $classes): array
    {
        $handlers = [];

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable() || !$reflection->implementsInterface(OperationHandler::class)) {
                continue;
            }

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), HoldsAuthority::class, true)) {
                    // Its operations() names its commands without reading anything.
                    $handler = $reflection->newInstanceWithoutConstructor();
                    \assert($handler instanceof OperationHandler);
                    $handlers[$class] = $handler;

                    break;
                }
            }
        }

        return $handlers;
    }

    /**
     * @param array<class-string, OperationHandler> $handlers
     *
     * @return array<class-string, list<class-string>>
     */
    private static function unmarked(array $handlers): array
    {
        $unmarked = [];

        foreach ($handlers as $class => $handler) {
            $commands = array_values(array_filter(array_keys($handler->operations()), static fn (string $command): bool => !is_subclass_of($command, ChangesAuthority::class)));

            if ([] !== $commands) {
                $unmarked[$class] = $commands;
            }
        }

        return $unmarked;
    }

    /**
     * Every class of YorvoID's packages.
     *
     * @return list<class-string>
     */
    private static function classes(): array
    {
        $classes = [];
        $root = \dirname(__DIR__, 2).'/packages';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);

            if ('php' !== $file->getExtension() || !str_contains($file->getPathname(), '/src/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (1 === preg_match('/^namespace ([^;]+);/m', $source, $namespace) && 1 === preg_match('/^(?:final |abstract |readonly )*class (\w+)/m', $source, $class)) {
                $name = $namespace[1].'\\'.$class[1];

                if (class_exists($name)) {
                    $classes[] = $name;
                }
            }
        }

        return $classes;
    }
}

/**
 * Writes roles, through a command that doesn't say it changes authority.
 */
final readonly class PlantedHandler implements OperationHandler
{
    public function __construct(
        private PolicyStore $policy,
    ) {
    }

    public function operations(): array
    {
        return [PlantedCommand::class => $this->run(...)];
    }

    public function run(): void
    {
        $this->policy->roles(new \YorvoID\Core\Realm('acme'));
    }
}

/**
 * @implements \YorvoID\Core\Operation\Command<null>
 */
final readonly class PlantedCommand implements \YorvoID\Core\Operation\Command
{
    public function __construct(
        public \YorvoID\Core\Realm $realm,
    ) {
    }
}
