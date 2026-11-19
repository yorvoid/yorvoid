<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A store of who may do what: roles, relations, attributes, delegations. The operations that write one
 * are commands that change authority (ChangesAuthority), whose effects are declared (D-51).
 *
 * @spi
 */
interface HoldsAuthority
{
}
