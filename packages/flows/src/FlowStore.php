<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * Keeps flows between requests, for one browser: the Symfony bridge keeps them in the PHP session, so
 * a flow can only be continued where it began.
 *
 * @api
 */
interface FlowStore
{
    public function get(string $id): ?FlowState;

    public function save(FlowState $state): void;

    public function delete(string $id): void;
}
