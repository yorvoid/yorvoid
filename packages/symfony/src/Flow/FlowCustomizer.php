<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Flow;

use YorvoID\Flows\Flow;

/**
 * Changes YorvoID's flows (PLT-070): insert, replace or remove steps. Every service implementing it
 * is given every flow, in turn.
 *
 * @api
 */
interface FlowCustomizer
{
    /**
     * @return Flow The flow as the application wants it: the same one if it isn't for this customizer
     */
    public function customize(Flow $flow): Flow;
}
