<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Testing\Contract\OneTimeTokenStoreConcurrencyContract;
use YorvoID\Testing\Contract\RecoveryCodeStoreConcurrencyContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The concurrency contracts catch stores that check and then act (TOOLING-03, the architect's R2):
 * - a token store that says it deleted what it found: a race of two calls, which the barrier forces;
 * - a recovery code store that looks for the code, then deletes it: a race of one call, which only
 *   the rounds catch, and not always.
 */
final class LosingStoreTest extends DatabaseTestCase
{
    use OneTimeTokenStoreConcurrencyContract {
        testOneTimeTokenStoreGivesATokenToOneOfManyAtOnce as private givesATokenToOne;
    }
    use RecoveryCodeStoreConcurrencyContract {
        testRecoveryCodeStoreLetsOneOfManyUseACodeAtOnce as private letsOneUseACode;
    }

    public static function implementations(): iterable
    {
        yield 'PostgreSQL, losing' => ['postgresql losing'];
    }

    protected function setUp(): void
    {
        $this->stack('postgresql'); // skipped without PostgreSQL to test against
    }

    #[DataProvider('implementations')]
    public function testOneTimeTokenStoreGivesATokenToOneOfManyAtOnce(string $implementation): void
    {
        $this->assertCaught(fn () => $this->givesATokenToOne($implementation));
    }

    #[DataProvider('implementations')]
    public function testRecoveryCodeStoreLetsOneOfManyUseACodeAtOnce(string $implementation): void
    {
        $this->assertCaught(fn () => $this->letsOneUseACode($implementation));
    }

    protected function storeFactory(): string
    {
        return __DIR__.'/../Support/race-stores.php';
    }

    private function assertCaught(\Closure $contract): void
    {
        try {
            $contract();
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('not exactly one of the processes', $failure->getMessage());

            return;
        }

        self::fail('The contract passed a store that checks and then acts.');
    }
}
