<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Secret;

/**
 * Whether a command holds a Secret, in one of its properties or in an object one holds: then its
 * OperationRan event carries no command, since a generic listener, e.g. one that queues events, must
 * never get a token or a device cookie. Found from the properties' declared types, once per class.
 *
 * @internal
 */
final class HoldsSecrets
{
    /** @var array<class-string, bool> */
    private static array $known = [];

    /**
     * @param class-string $class
     */
    public static function in(string $class): bool
    {
        return self::$known[$class] ??= self::holds($class, []);
    }

    /**
     * @param class-string              $class
     * @param array<class-string, true> $seen  The classes on the way here, which hold no Secret but through this one
     */
    private static function holds(string $class, array $seen): bool
    {
        // Secret is final: no other class is one.
        if (Secret::class === $class) {
            return true;
        }

        $seen[$class] = true;

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            foreach (self::classes($property->getType()) as $type) {
                if (!($seen[$type] ?? false) && self::holds($type, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<class-string> The classes a declared type names
     */
    private static function classes(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();

            return !$type->isBuiltin() && (class_exists($name) || interface_exists($name)) ? [$name] : [];
        }

        if (null === $type) {
            return [];
        }

        \assert($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType);

        return array_merge(...array_map(self::classes(...), $type->getTypes()));
    }
}
