<?php

declare(strict_types=1);

namespace YorvoID\Core\Event;

/**
 * What started an operation.
 *
 * @api
 */
enum OriginKind: string
{
    /** A browser request */
    case Web = 'web';

    /** An API request: a token, an agent, another system */
    case Api = 'api';

    /** A console command */
    case Console = 'console';

    /** A background job */
    case Job = 'job';

    /** YorvoID itself, e.g. pruning expired tokens */
    case System = 'system';
}
