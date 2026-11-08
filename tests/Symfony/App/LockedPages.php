<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

/**
 * An application's own forbid, registered by itself: nobody touches a locked page.
 */
final readonly class LockedPages implements ForbidElement
{
    public function name(): string
    {
        return 'acme.locked_pages';
    }

    public function matches(Request $request): bool
    {
        return 'page' === $request->resource?->type;
    }

    public function evaluate(Request $request): Truth
    {
        return 'locked' === $request->resource?->id ? Truth::holds('The page is locked.') : Truth::fails('The page is open.');
    }
}
