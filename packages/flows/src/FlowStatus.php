<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * @api
 */
enum FlowStatus: string
{
    /** A step is waiting for the person */
    case InProgress = 'in_progress';

    /** Every step is done or passed over */
    case Completed = 'completed';

    /** A step found the flow can't go on */
    case Failed = 'failed';
}
