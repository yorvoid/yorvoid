<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

/**
 * A published template, against the version it was published from and YorvoID's current one.
 *
 * @api
 */
enum TemplateStatus: string
{
    /** YorvoID's hasn't changed since it was published */
    case UpToDate = 'up_to_date';

    /** YorvoID's changed, and the application's copy didn't: it can take the new one as it is */
    case Upgradable = 'upgradable';

    /** Both changed: merge YorvoID's changes into the application's copy */
    case Diverged = 'diverged';

    /** YorvoID doesn't have it any more */
    case Removed = 'removed';

    /** It wasn't published: YorvoID's is used, or one the application wrote itself */
    case NotPublished = 'not_published';
}
