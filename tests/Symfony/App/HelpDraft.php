<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

/**
 * An application's own object as isGranted()'s subject, which doesn't say which resource it is: the
 * application's rules read it.
 */
final readonly class HelpDraft
{
}
