<?php

declare(strict_types=1);

namespace YorvoID\PhpstanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags building YorvoID's decision point (new Operations(...)) in an application: an application has
 * one, which its bundle or composition root builds, and a second one over another Authorization issues
 * permits that the decision log never sees. Where it's built on purpose, the composition root of an
 * application without the Symfony bundle, ignore the error there once.
 *
 * @implements Rule<Node\Expr\New_>
 */
final readonly class DecisionPointRule implements Rule
{
    private const string OPERATIONS = 'YorvoID\Core\Operation\Operations';

    private const string DOCS = 'https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/operations-and-events.md#the-decision-point';

    public function __construct(
        private YorvoIDCode $yorvoid,
    ) {
    }

    public function getNodeType(): string
    {
        return Node\Expr\New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Node\Name || self::OPERATIONS !== $scope->resolveName($node->class) || $this->yorvoid->contains($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('A second YorvoID decision point: an application has one Operations, which its bundle or composition root builds. Another one\'s permits aren\'t in the decision log.')
                ->identifier('yorvoid.decisionPoint')
                ->tip('Ask for the Operations service. Building it on purpose, in a composition root without the bundle: ignore this error there. See '.self::DOCS)
                ->build(),
        ];
    }
}
