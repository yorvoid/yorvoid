<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Core\Operation\ConcernsNoResource;

/**
 * An application's own object as isGranted()'s subject, about no resource: the application's rules
 * read it.
 */
final readonly class HelpDraft implements ConcernsNoResource
{
}
