<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Principal\PrincipalId;

/**
 * A subject that doesn't say which resource it is, as a host's content items don't: the application
 * gives the resource with it.
 */
final readonly class ContentItem implements Authored
{
    public function __construct(
        private PrincipalId $author,
    ) {
    }

    public function author(): PrincipalId
    {
        return $this->author;
    }

    public function classification(): string
    {
        return 'public';
    }
}
