<?php

declare(strict_types=1);

namespace YorvoID\Tools\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Refuses an @api class whose surface names an @internal class: a parameter, a return type or a
 * property, native or in the docblock. The compatibility promise covers the @api class, so
 * integrators would depend on a class the promise lets change in any release (architecture audit
 * A-1). A member that is itself @internal, like a constructor only the container calls, is outside
 * the promise and may.
 *
 * @implements Rule<InClassNode>
 */
final readonly class ApiExposesInternalRule implements Rule
{
    public function __construct(private ReflectionProvider $reflection)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $native = $class->getNativeReflection();
        $doc = $native->getDocComment();

        if (!\is_string($doc) || 1 !== preg_match('/@api\b/', $doc)) {
            return [];
        }

        $errors = [];

        foreach ($native->getMethods() as $declared) {
            if ($declared->getDeclaringClass()->getName() !== $class->getName() || !self::visible($class, $declared->isPublic(), $declared->isProtected())) {
                continue;
            }

            $method = $class->getNativeMethod($declared->getName());

            if ($method->isInternal()->yes()) {
                continue;
            }

            $types = [];

            foreach ($method->getVariants() as $variant) {
                $types[] = $variant->getReturnType();

                foreach ($variant->getParameters() as $parameter) {
                    $types[] = $parameter->getType();
                }
            }

            $line = $declared->getStartLine();
            $errors = [...$errors, ...$this->errors(\sprintf('%s::%s()', $class->getName(), $declared->getName()), $types, false === $line ? $node->getStartLine() : $line)];
        }

        foreach ($native->getProperties() as $declared) {
            if ($declared->getDeclaringClass()->getName() !== $class->getName() || !self::visible($class, $declared->isPublic(), $declared->isProtected())) {
                continue;
            }

            $property = $class->getNativeProperty($declared->getName());

            if ($property->isInternal()->yes()) {
                continue;
            }

            $errors = [...$errors, ...$this->errors(\sprintf('%s::$%s', $class->getName(), $declared->getName()), [$property->getReadableType()], $node->getStartLine())];
        }

        return $errors;
    }

    /**
     * Public, or protected in a class that isn't final, which a subclass sees.
     */
    private static function visible(ClassReflection $class, bool $public, bool $protected): bool
    {
        return $public || ($protected && !$class->isFinal());
    }

    /**
     * @param list<Type> $types
     *
     * @return list<IdentifierRuleError>
     */
    private function errors(string $member, array $types, int $line): array
    {
        $names = [];

        foreach ($types as $type) {
            foreach ($type->getReferencedClasses() as $name) {
                if ($this->reflection->hasClass($name) && $this->reflection->getClass($name)->isInternal()) {
                    $names[$name] = true;
                }
            }
        }

        $errors = [];

        foreach (array_keys($names) as $name) {
            $errors[] = RuleErrorBuilder::message(\sprintf('%s is @api but names %s, which is @internal: mark the member @internal, or the class @api.', $member, $name))
                ->identifier('yorvoid.apiExposesInternal')
                ->line($line)
                ->build();
        }

        return $errors;
    }
}
