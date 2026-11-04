<?php

declare(strict_types=1);

namespace YorvoID\Tools\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Refuses a package's code naming another package's @internal class: packages are released with the
 * same version, but installed one by one, and an internal class is free to change in any release
 * (BND-10). Tests and tools may use internals. Checked on imports: the coding standard imports every
 * class a file names.
 *
 * @implements Rule<Node\Stmt>
 */
final readonly class InternalAcrossPackagesRule implements Rule
{
    /** YorvoID\<Package>\…: the namespaces that aren't packages */
    private const array NOT_PACKAGES = ['Tests', 'Tools', 'E2e'];

    public function __construct(private ReflectionProvider $reflection)
    {
    }

    public function getNodeType(): string
    {
        return Node\Stmt::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $here = self::package($scope->getNamespace() ?? '');

        if (null === $here || !($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse)) {
            return [];
        }

        $errors = [];

        foreach ($node->uses as $use) {
            // Classes only, not functions or constants: in a group, each item may say which it is.
            if (Node\Stmt\Use_::TYPE_NORMAL !== (Node\Stmt\Use_::TYPE_UNKNOWN === $use->type ? $node->type : $use->type)) {
                continue;
            }

            $name = ($node instanceof Node\Stmt\GroupUse ? Node\Name::concat($node->prefix, $use->name) : $use->name)?->toString() ?? '';
            $there = self::package($name);

            if (null === $there || $here === $there || !$this->reflection->hasClass($name) || !$this->reflection->getClass($name)->isInternal()) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf('%s is internal to yorvoid/%s: mark it @api, or keep it out of yorvoid/%s.', $name, self::composerName($there), self::composerName($here)))
                ->identifier('yorvoid.internalAcrossPackages')
                ->line($use->getStartLine())
                ->build();
        }

        return $errors;
    }

    private static function package(string $name): ?string
    {
        $parts = explode('\\', ltrim($name, '\\'));

        return 'YorvoID' === $parts[0] && isset($parts[1], $parts[2]) && !\in_array($parts[1], self::NOT_PACKAGES, true) ? $parts[1] : null;
    }

    private static function composerName(string $package): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $package));
    }
}
