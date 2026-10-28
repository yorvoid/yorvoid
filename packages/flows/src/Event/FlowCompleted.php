<?php

declare(strict_types=1);

namespace YorvoID\Flows\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Flows\FlowState;

/**
 * A flow's steps are all done or passed over: keep what your own steps collected, from its state.
 *
 * @api
 */
final readonly class FlowCompleted
{
    public function __construct(
        public FlowState $state,
        public Origin $origin,
    ) {
    }
}
