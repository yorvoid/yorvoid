<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A rule that lets a request through when its condition holds and nothing forbids it. It speaks only
 * for the operations that one of their #[Operation]'s AuthorizedBy names: an ability's permit never
 * permits an operation only the host's operator runs.
 *
 * @api
 */
interface PermitElement extends PolicyElement
{
    /** What it authorizes by, e.g. AuthorizedBy::Ability; the permit it gives carries it */
    public function basis(): AuthorizedBy;
}
