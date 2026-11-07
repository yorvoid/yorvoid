<?php

declare(strict_types=1);

namespace YorvoID\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * A decision's verdict is read only through a match with an arm for every case and no default: never
 * compared with ==, ===, !=, !== or <=> (nor its value), looked up with in_array() or array_search(), or
 * switched on. So nothing treats Permit-offline or Indeterminate as Permit by accident, and a new case
 * is a PHPStan error wherever it isn't handled (P1, P1′). YorvoID's tests are left out: they assert.
 *
 * @implements Rule<Node>
 */
final class VerdictMatchRule implements Rule
{
    public const string VERDICT = 'YorvoID\Core\Operation\Verdict';

    private const array COMPARISONS = [
        Expr\BinaryOp\Equal::class,
        Expr\BinaryOp\NotEqual::class,
        Expr\BinaryOp\Identical::class,
        Expr\BinaryOp\NotIdentical::class,
        Expr\BinaryOp\Spaceship::class,
    ];

    private const array LOOKUPS = ['in_array', 'array_search', 'array_keys'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (str_starts_with($scope->getNamespace() ?? '', 'YorvoID\Tests')) {
            return [];
        }

        $refused = match (true) {
            $node instanceof Expr\Match_ => $this->isVerdict($node->cond, $scope) && [] !== array_filter($node->arms, static fn (Node\MatchArm $arm): bool => null === $arm->conds),
            $node instanceof Stmt\Switch_ => $this->isVerdict($node->cond, $scope),
            // Whether there's a verdict at all (=== null) isn't reading one.
            $node instanceof Expr\BinaryOp && \in_array($node::class, self::COMPARISONS, true) => !self::isNull($node->left) && !self::isNull($node->right) && ($this->isVerdict($node->left, $scope) || $this->isVerdict($node->right, $scope)),
            $node instanceof Expr\FuncCall && !$node->isFirstClassCallable() && $node->name instanceof Node\Name && \in_array($node->name->toLowerString(), self::LOOKUPS, true) => [] !== array_filter($node->getArgs(), fn (Node\Arg $arg): bool => $this->isVerdict($arg->value, $scope)),
            default => false,
        };

        if (!$refused) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Read a Verdict with a match that has an arm for every case and no default, so Permit-offline and Indeterminate are never taken for Permit (P1, P1′).')
                ->identifier('yorvoid.verdictMatch')
                ->build(),
        ];
    }

    private static function isNull(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && 'null' === $expr->name->toLowerString();
    }

    /**
     * A verdict, or its value.
     */
    private function isVerdict(Expr $expr, Scope $scope): bool
    {
        $verdict = new ObjectType(self::VERDICT);

        // A verdict for certain, or none: mixed values aren't verdicts, nor is null.
        $type = TypeCombinator::removeNull($scope->getType($expr));

        if (!$type instanceof NeverType && $verdict->isSuperTypeOf($type)->yes()) {
            return true;
        }

        return ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch)
            && $expr->name instanceof Node\Identifier
            && 'value' === $expr->name->toString()
            && $verdict->isSuperTypeOf(TypeCombinator::removeNull($scope->getType($expr->var)))->yes()
            && !TypeCombinator::removeNull($scope->getType($expr->var)) instanceof NeverType;
    }
}
