<?php

declare(strict_types=1);

/*
 * What an application without Symfony gives vendor/bin/yorvoid-policy: its policy, built by the
 * function production builds it with (here PolicyWorld), and its cases. YORVOID_POLICY_FAIL adds a case
 * that fails.
 */

use YorvoID\PolicyTesting\Mutation\Mutants;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyCases;
use YorvoID\PolicyTesting\PolicyServices;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\PolicyUnderTest;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

$policy = PolicyUnderTest::of(static function (InMemoryStorage $stores, Mutants $mutants): PolicyServices {
    $world = new PolicyWorld(memory: $stores, mutants: $mutants);

    return new PolicyServices($world->operations, $world->policy, $world->realm);
});

return new PolicySuite($policy, new readonly class implements PolicyCases {
    public function cases(): iterable
    {
        yield PolicyCase::that('An editor publishes')->given(roles: ['editor'])->asks('acme.pages.publish')->permits();

        if (false !== getenv('YORVOID_POLICY_FAIL')) {
            yield PolicyCase::that('A member publishes')->given(roles: ['member'])->asks('acme.pages.publish')->permits();
        }
    }
});
