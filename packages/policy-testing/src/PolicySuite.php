<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

/**
 * A policy and its cases: what vendor/bin/yorvoid-policy's bootstrap file returns.
 *
 * ```php
 * return new PolicySuite(PolicyUnderTest::of(...), new AcmeCases());
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicySuite
{
    /** @var list<PolicyCases> */
    public array $cases;

    public function __construct(
        public PolicyUnderTest $policy,
        PolicyCases ...$cases,
    ) {
        $all = [];

        foreach ($cases as $one) {
            $all[] = $one;
        }

        $this->cases = $all;
    }

    /**
     * Every case, in order.
     *
     * @return list<PolicyCase>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->cases as $cases) {
            foreach ($cases->cases() as $case) {
                $all[] = $case;
            }
        }

        return $all;
    }
}
