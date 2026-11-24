<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Delegable;
use YorvoID\Core\Authorization\ListsActions;

/**
 * The catalogue's abilities, for delegations: each with to whom it may be delegated.
 *
 * @api
 *
 * @experimental
 */
final readonly class AbilityActions implements ListsActions
{
    public function __construct(
        private PolicyCatalogue $catalogue,
    ) {
    }

    public function actions(): array
    {
        return array_map(static fn (Ability $ability): Delegable => $ability->delegation(), $this->catalogue->abilities());
    }
}
