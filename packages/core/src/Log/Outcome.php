<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

/**
 * @api
 */
enum Outcome: string
{
    /** It happened */
    case Success = 'success';

    /** It was refused: not allowed, or a check failed */
    case Denied = 'denied';

    /** It was attempted and failed for another reason */
    case Failed = 'failed';
}
