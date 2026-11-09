<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

use YorvoID\Testing\InMemoryStorage;

/**
 * The application's policy, as production builds it, on in-memory stores: each case gets it with
 * nothing stored. Build it the way production does, or the tests check another policy than the one
 * that runs:
 *
 * - in Symfony, from a kernel in the "yorvoid_policy" environment ({@see Symfony\PolicyKernel});
 * - in plain PHP, with the function that builds the policy in production, given in-memory stores:
 *
 * ```php
 * $policy = PolicyUnderTest::of(static fn (InMemoryStorage $stores): PolicyServices => Acme\Security::policy($stores->policy, $stores->relations, ...));
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicyUnderTest
{
    /**
     * @param \Closure(): PolicyServices $fresh The policy on stores with nothing in them, each time it's called
     */
    public function __construct(
        private \Closure $fresh,
    ) {
    }

    /**
     * @param \Closure(InMemoryStorage): PolicyServices $build Production's own function, given new in-memory stores for each case
     */
    public static function of(\Closure $build): self
    {
        return new self(static fn (): PolicyServices => $build(new InMemoryStorage()));
    }

    /**
     * The policy, with nothing stored.
     */
    public function fresh(): PolicyServices
    {
        return ($this->fresh)();
    }
}
