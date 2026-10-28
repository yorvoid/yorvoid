<?php

declare(strict_types=1);

namespace YorvoID\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Refuses comparing a revealed secret with ==, ===, !=, !==, <> or <=>, or with the strcmp() family:
 * those stop at the first difference, so their timing tells an attacker how much of a guess was right.
 * Secrets are compared with Secret::equals(), which uses hash_equals() (PLT-053).
 *
 * @implements Rule<Expr>
 */
final class SecretComparisonRule implements Rule
{
    public const string SECRET = 'YorvoID\Core\Secret';

    private const array COMPARISONS = [
        Expr\BinaryOp\Equal::class,
        Expr\BinaryOp\NotEqual::class,
        Expr\BinaryOp\Identical::class,
        Expr\BinaryOp\NotIdentical::class,
        Expr\BinaryOp\Spaceship::class,
    ];

    private const array STRING_COMPARISONS = ['strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'strnatcmp', 'strnatcasecmp', 'substr_compare'];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $operands = [];

        if (\in_array($node::class, self::COMPARISONS, true)) {
            \assert($node instanceof Expr\BinaryOp);
            $operands = [$node->left, $node->right];
        } elseif ($node instanceof Expr\FuncCall && !$node->isFirstClassCallable() && $node->name instanceof Node\Name && \in_array($node->name->toLowerString(), self::STRING_COMPARISONS, true)) {
            $operands = array_map(static fn (Node\Arg $arg): Expr => $arg->value, $node->getArgs());
        }

        foreach ($operands as $operand) {
            if ($this->isRevealedSecret($operand, $scope)) {
                return [
                    RuleErrorBuilder::message('A revealed secret is compared in variable time; use Secret::equals(), which compares in constant time (PLT-053).')
                        ->identifier('yorvoid.secretComparison')
                        ->build(),
                ];
            }
        }

        return [];
    }

    private function isRevealedSecret(Expr $expr, Scope $scope): bool
    {
        return ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
            && $expr->name instanceof Node\Identifier
            && 'reveal' === $expr->name->toLowerString()
            && new ObjectType(self::SECRET)->isSuperTypeOf($scope->getType($expr->var))->yes();
    }
}
