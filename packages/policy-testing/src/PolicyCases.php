<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

/**
 * The application's policy cases. In Symfony, a service implementing it is collected by itself.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
interface PolicyCases
{
    /**
     * @return iterable<PolicyCase>
     */
    public function cases(): iterable;
}
