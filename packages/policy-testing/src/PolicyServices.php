<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Realm;
use YorvoID\Policy\Policy;

/**
 * The application's policy as it decides, on stores with nothing in them: the operations a case is set
 * up with, the policy it asks, and the realm.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicyServices
{
    public function __construct(
        public Operations $operations,
        public Policy $policy,
        public Realm $realm,
    ) {
    }
}
