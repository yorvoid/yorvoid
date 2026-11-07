<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Realm;

/**
 * @implements Command<null>
 */
final readonly class UpdateSite implements Command, ConcernsResource
{
    public function __construct(
        public Realm $realm,
        public string $site,
    ) {
    }

    public function resource(): ResourceRef
    {
        return new ResourceRef('site', $this->site);
    }
}
