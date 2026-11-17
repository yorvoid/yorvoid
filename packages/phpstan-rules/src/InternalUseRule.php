<?php

declare(strict_types=1);

namespace YorvoID\PhpstanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Flags an application's use of what YorvoID marks @internal: a method it calls, a property it reads,
 * a class it creates, whether the class or the member is marked. They're YorvoID's own, e.g. what one
 * of its packages calls in another (Sessions::stepUp(), for the packages that check a proof), and
 * change in any minor release.
 *
 * @implements Rule<Node\Expr>
 */
final readonly class InternalUseRule implements Rule
{
    private const string DOCS = 'https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered';

    public function __construct(
        private YorvoIDCode $yorvoid,
        private ReflectionProvider $reflection,
    ) {
    }

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->yorvoid->contains($scope)) {
            return [];
        }

        // Nullsafe calls and fetches reach the rule as plain ones too.
        $used = match (true) {
            $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier => $this->method($scope->getType($node->var), $node->name->toString(), $scope),
            $node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier => $this->method($this->classType($node->class, $scope), $node->name->toString(), $scope),
            $node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier => $this->property($scope->getType($node->var), $node->name->toString(), $scope),
            $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name => $this->created($scope->resolveName($node->class)),
            default => null,
        };

        if (null === $used) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf('%s is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', $used))
                ->identifier('yorvoid.internalUse')
                ->tip('See '.self::DOCS)
                ->build(),
        ];
    }

    /**
     * The first of the type's classes whose method is internal: one error for the call.
     */
    private function method(Type $type, string $name, Scope $scope): ?string
    {
        foreach ($type->getObjectClassReflections() as $class) {
            if (!$class->hasMethod($name)) {
                continue;
            }

            $method = $class->getMethod($name, $scope);

            if ($this->internal($method->getDeclaringClass(), $method->isInternal()->yes())) {
                return $method->getDeclaringClass()->getName().'::'.$method->getName().'()';
            }
        }

        return null;
    }

    private function property(Type $type, string $name, Scope $scope): ?string
    {
        foreach ($type->getObjectClassReflections() as $class) {
            if (!$class->hasProperty($name)) {
                continue;
            }

            $property = $class->getProperty($name, $scope);

            if ($this->internal($property->getDeclaringClass(), str_contains((string) $property->getDocComment(), '@internal'))) {
                return $property->getDeclaringClass()->getName().'::$'.$name;
            }
        }

        return null;
    }

    /**
     * A class that's internal, or whose constructor is, its own or one it inherits.
     */
    private function created(string $name): ?string
    {
        if (!$this->reflection->hasClass($name)) {
            return null;
        }

        $class = $this->reflection->getClass($name);
        $constructor = $class->hasConstructor() ? $class->getConstructor() : null;

        return $this->internal($class, true === $constructor?->isInternal()->yes()) ? 'new '.$class->getName() : null;
    }

    private function classType(Node\Name|Node\Expr $class, Scope $scope): Type
    {
        return $class instanceof Node\Name ? $scope->resolveTypeByName($class) : $scope->getType($class);
    }

    /**
     * Whether what's used is YorvoID's, and marked @internal, on itself or on its class.
     */
    private function internal(ClassReflection $class, bool $member): bool
    {
        return $this->yorvoid->declares($class) && ($member || $class->isInternal());
    }
}
