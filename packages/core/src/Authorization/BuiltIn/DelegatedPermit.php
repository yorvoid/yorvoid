<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * The only permit a request under a delegation has: its root's, decided now, on the basis the root was
 * permitted by. The evaluator makes it per request; it's never in a policy.
 *
 * @internal
 */
final readonly class DelegatedPermit implements PermitElement
{
    public const string NAME = 'yorvoid.delegation.root';

    public function __construct(
        private AuthorizedBy $basis,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function basis(): AuthorizedBy
    {
        return $this->basis;
    }

    public function matches(Request $request): bool
    {
        return true;
    }

    public function evaluate(Request $request): Truth
    {
        return Truth::holds('Its root may.');
    }
}
