<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use YorvoID\Core\Operation\Permit;

/**
 * The public methods, without a Permit, of every YorvoID service an application can reach from the
 * Symfony bundle: public ones, and private ones whose ID is their class or interface, which autowiring
 * gives anyone who asks for that type. Collected before Symfony removes what nothing uses. Classified
 * in WritesWithoutPermit.
 */
final class PublicMethodsWithoutPermit implements CompilerPassInterface
{
    /** @var list<string> Class::method, sorted */
    public array $methods = [];

    public function process(ContainerBuilder $container): void
    {
        $types = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            if ($definition->isPublic()) {
                $types[] = $definition->getClass() ?? $id;
            }

            $types[] = $id;
        }

        foreach ($container->getAliases() as $id => $alias) {
            $types[] = $id;
        }

        $methods = [];

        foreach (array_unique($types) as $type) {
            // YorvoID's own types only: an ID that names one is what autowiring finds.
            if (!str_starts_with($type, 'YorvoID\\') || str_starts_with($type, 'YorvoID\\Tests\\') || !(class_exists($type) || interface_exists($type))) {
                continue;
            }

            $class = new \ReflectionClass($type);

            if (str_contains((string) $class->getDocComment(), '@internal')) {
                continue;
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== $class->getName() || 'operations' === $method->getName() || str_contains((string) $method->getDocComment(), '@internal') || self::takesAPermit($method)) {
                    continue;
                }

                $methods[] = $class->getName().'::'.$method->getName();
            }
        }

        $methods = array_values(array_unique($methods));
        sort($methods);
        $this->methods = $methods;
    }

    private static function takesAPermit(\ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && Permit::class === $type->getName()) {
                return true;
            }
        }

        return false;
    }
}
