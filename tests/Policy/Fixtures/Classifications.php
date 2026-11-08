<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Attribute\AttributeResolver;

/**
 * A page's classification, from the subject the application gave.
 */
final readonly class Classifications implements AttributeResolver
{
    public function resolves(string $name): bool
    {
        return 'acme.classification' === $name;
    }

    public function resolve(Request $request, string $name): ?string
    {
        return $request->subject instanceof Authored ? $request->subject->classification() : null;
    }
}
