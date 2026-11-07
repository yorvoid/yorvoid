<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\ConcernsResource;

/**
 * An application's own object as isGranted()'s subject, saying which resource it is.
 */
final readonly class LockedPage implements ConcernsResource
{
    public function resource(): ResourceRef
    {
        return new ResourceRef('page', 'locked');
    }
}
