<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Actions a catalogue knows, and to whom each may be delegated: what a delegation's patterns are expanded
 * against when it's issued, and checked against at every use. Policy lists its abilities; core lists
 * the operations (Actions).
 *
 * @spi
 *
 * @experimental
 */
interface ListsActions
{
    /**
     * @return array<string, Delegable> By action's name
     */
    public function actions(): array;
}
