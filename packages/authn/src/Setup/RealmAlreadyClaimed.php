<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Realm;

/**
 * The realm was claimed with a setup code already; setup codes work only once per realm. Someone who
 * lost access recovers it another way, e.g. with an operator's recovery link.
 *
 * @api
 */
final class RealmAlreadyClaimed extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Realm $realm,
    ) {
        parent::__construct(\sprintf('The realm "%s" was claimed already; use a recovery link to regain access.', $realm->name));
    }
}
