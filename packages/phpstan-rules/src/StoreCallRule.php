<?php

declare(strict_types=1);

namespace YorvoID\PhpstanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags a call to one of YorvoID's stores, which are for implementing, not calling (@spi): a call
 * changes YorvoID's records, or reads them, without asking YorvoID, so nothing is decided, logged or
 * kept consistent. Every method, reads too. A class that implements the store may call the one it
 * wraps, and YorvoID's store contracts call the store they test.
 *
 * @implements Rule<Node\Expr>
 */
final readonly class StoreCallRule implements Rule
{
    private const string DOCS = 'https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement';

    public function __construct(
        private YorvoIDCode $yorvoid,
    ) {
    }

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // A nullsafe call reaches the rule as a method call too.
        if (!$node instanceof Node\Expr\MethodCall || !$node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();

        foreach ($scope->getType($node->var)->getObjectClassReflections() as $class) {
            $store = $this->store($class, $method);

            if (null === $store) {
                continue;
            }

            if ($this->yorvoid->contains($scope) || true === $scope->getClassReflection()?->implementsInterface($store->getName())) {
                return [];
            }

            // One error for the call.
            return [
                RuleErrorBuilder::message(\sprintf('%s::%s() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', $store->getName(), $method))
                    ->identifier('yorvoid.storeCall')
                    ->tip('See '.self::DOCS)
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * YorvoID's @spi interface, the class itself or one it implements, that declares the method.
     */
    private function store(ClassReflection $class, string $method): ?ClassReflection
    {
        foreach ([$class, ...$class->getInterfaces()] as $candidate) {
            if ($candidate->isInterface() && $candidate->hasNativeMethod($method) && $this->yorvoid->declares($candidate) && str_contains((string) $candidate->getNativeReflection()->getDocComment(), '@spi')) {
                return $candidate;
            }
        }

        return null;
    }
}
