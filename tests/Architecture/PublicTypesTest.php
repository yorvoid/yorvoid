<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * What YorvoID's public API is made of: no third-party type that would tie an application to a library
 * YorvoID uses inside (M3-0, A-4). Every `@api` or `@spi` class, interface and trait of the packages,
 * yorvoid/testing's contracts too: their public properties, parameters and return types, unions and
 * nullables included, but not `@internal` members.
 */
final class PublicTypesTest extends TestCase
{
    /** Namespaces whose types the public API doesn't take or give; 0.6-2 adds the others but PSR's */
    private const array FORBIDDEN = ['Symfony\\Component\\Uid\\'];

    public function testThePublicApiUsesNoForbiddenType(): void
    {
        $found = [];
        $checked = 0;

        foreach (self::published() as $name) {
            $class = new \ReflectionClass($name);
            ++$checked;

            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $name && !self::internal($property->getDocComment())) {
                    array_push($found, ...self::forbidden($property->getType(), \sprintf('%s::$%s', $name, $property->getName())));
                }
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || self::internal($method->getDocComment())) {
                    continue;
                }

                array_push($found, ...self::forbidden($method->getReturnType(), \sprintf('%s::%s() returns', $name, $method->getName())));

                foreach ($method->getParameters() as $parameter) {
                    array_push($found, ...self::forbidden($parameter->getType(), \sprintf('%s::%s($%s)', $name, $method->getName(), $parameter->getName())));
                }
            }
        }

        self::assertGreaterThan(300, $checked, 'The published classes weren\'t found.');
        self::assertSame([], $found, 'A third-party type in the public API: wrap it in one of YorvoID\'s, e.g. YorvoID\Core\Id\Id.');
    }

    /**
     * @return list<string>
     */
    private static function forbidden(?\ReflectionType $type, string $where): array
    {
        $names = match (true) {
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType, $type instanceof \ReflectionIntersectionType => array_map(static fn (\ReflectionType $part): string => $part instanceof \ReflectionNamedType ? $part->getName() : (string) $part, $type->getTypes()),
            default => [],
        };
        $found = [];

        foreach ($names as $name) {
            foreach (self::FORBIDDEN as $namespace) {
                if (str_starts_with(ltrim($name, '?\\'), $namespace)) {
                    $found[] = \sprintf('%s: %s', $where, $name);
                }
            }
        }

        return $found;
    }

    private static function internal(string|false $docComment): bool
    {
        return false !== $docComment && str_contains($docComment, '@internal');
    }

    /**
     * The classes, interfaces and traits of YorvoID's packages marked @api or @spi.
     *
     * @return list<class-string>
     */
    private static function published(): array
    {
        $published = [];
        $root = \dirname(__DIR__, 2).'/packages';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);

            if ('php' !== $file->getExtension() || !str_contains($file->getPathname(), '/src/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (1 !== preg_match('/^namespace ([^;]+);/m', $source, $namespace) || 1 !== preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', $source, $declared)) {
                continue;
            }

            $name = $namespace[1].'\\'.$declared[1];

            if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
                continue;
            }

            $docComment = new \ReflectionClass($name)->getDocComment();

            if (false !== $docComment && 1 === preg_match('/@(?:api|spi)\b/', $docComment)) {
                $published[] = $name;
            }
        }

        return $published;
    }
}
