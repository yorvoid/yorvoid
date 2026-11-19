<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

/**
 * On a test case of a store contract: the test case that runs the store's concurrency contract, when
 * it's another one (e.g. because this one also checks a store in memory).
 *
 * ```php
 * #[ConcurrencyTestedIn(MyStoreConcurrencyTest::class)]
 * final class MyOneTimeTokenStoreTest extends TestCase
 * {
 *     use OneTimeTokenStoreContract;
 * }
 * ```
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ConcurrencyTestedIn
{
    /**
     * @param class-string $testCase
     */
    public function __construct(
        public string $testCase,
    ) {
    }
}
