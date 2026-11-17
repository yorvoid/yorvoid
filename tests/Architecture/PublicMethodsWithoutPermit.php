<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use Symfony\Component\DependencyInjection\Container;
use YorvoID\Core\Operation\Permit;

/**
 * The public methods, without a Permit, of YorvoID's services that the Symfony bundle gives
 * applications: what WritesWithoutPermit classifies.
 */
final class PublicMethodsWithoutPermit
{
    /**
     * @return list<string> Class::method, sorted
     */
    public static function of(Container $container): array
    {
        $methods = [];

        // A compiled container lists its public services only.
        foreach ($container->getServiceIds() as $id) {
            // The test kernel's own services aren't YorvoID's.
            if (!str_starts_with($id, 'YorvoID\\') || str_starts_with($id, 'YorvoID\\Tests\\') || !$container->has($id)) {
                continue;
            }

            $class = $container->get($id)::class;

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== $class || 'operations' === $method->getName() || str_contains((string) $method->getDocComment(), '@internal') || self::takesAPermit($method)) {
                    continue;
                }

                $methods[] = $class.'::'.$method->getName();
            }
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
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
