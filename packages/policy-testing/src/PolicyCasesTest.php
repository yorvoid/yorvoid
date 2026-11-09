<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the policy's cases with the application's own tests, one test for each case:
 *
 * ```php
 * final class PolicyTest extends TestCase
 * {
 *     use PolicyCasesTest;
 *
 *     protected static function policySuite(): PolicySuite
 *     {
 *         return new PolicySuite(PolicyKernel::underTest(new Kernel('yorvoid_policy', false)), new AcmeCases());
 *     }
 * }
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
trait PolicyCasesTest
{
    private static ?PolicySuite $yorvoidPolicySuite = null;

    /**
     * The policy and its cases: built once for the test case.
     */
    abstract protected static function policySuite(): PolicySuite;

    /**
     * @return iterable<string, array{PolicyCase}>
     */
    public static function policyCases(): iterable
    {
        foreach (PolicyTests::named(self::yorvoidPolicySuite()->all()) as $description => $case) {
            yield $description => [$case];
        }
    }

    #[DataProvider('policyCases')]
    public function testThePolicyDecidesAsTheCaseSays(PolicyCase $case): void
    {
        $result = new PolicyTests()->one(self::yorvoidPolicySuite()->policy, $case);

        self::assertNull($result->failure, (string) $result->failure);
    }

    private static function yorvoidPolicySuite(): PolicySuite
    {
        return self::$yorvoidPolicySuite ??= static::policySuite();
    }
}
