<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Principal\PrincipalId;

/**
 * An application's page, the subject of its decisions, saying which resource it is.
 */
final readonly class Page implements Authored, ConcernsResource
{
    public function __construct(
        public string $id,
        private PrincipalId $author,
        private string $classification = 'public',
    ) {
    }

    public function resource(): ResourceRef
    {
        return new ResourceRef('page', $this->id);
    }

    public function author(): PrincipalId
    {
        return $this->author;
    }

    public function classification(): string
    {
        return $this->classification;
    }
}
