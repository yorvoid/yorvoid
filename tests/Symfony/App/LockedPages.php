<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\IndeterminateReason;
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
        return match ($request->resource?->id) {
            'locked' => Truth::holds('The page is locked.'),
            // Its lock is somewhere that can't be read.
            'unreadable' => Truth::unknown(IndeterminateReason::MissingData, 'The page\'s lock can\'t be read.'),
            default => Truth::fails('The page is open.'),
        };
    }
}
