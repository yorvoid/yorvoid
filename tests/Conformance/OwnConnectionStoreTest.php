<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Testing\Contract\PolicyStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The kit catches a store that doesn't take part in the transactions it's given (AR-09, G-10's exit): a
 * DbalPolicyStore on a connection of its own passes every other test of the contract, and kept a
 * rolled-back role assignment before the contract ran stores inside a transaction.
 */
final class OwnConnectionStoreTest extends DatabaseTestCase
{
    use PolicyStoreContract {
        testPolicyStoreTakesPartInTheTransactions as private takesPartInTheTransactions;
    }

    private ?PolicyStore $own = null;

    public static function implementations(): iterable
    {
        yield 'PostgreSQL' => ['postgresql'];
    }

    #[DataProvider('implementations')]
    public function testPolicyStoreTakesPartInTheTransactions(string $implementation): void
    {
        try {
            $this->takesPartInTheTransactions($implementation);
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('does the store take part in the transactions', $failure->getMessage());

            return;
        }

        self::fail('The contract passed a store on a connection of its own.');
    }

    protected function policyStore(string $implementation): PolicyStore
    {
        $this->stackOf($implementation); // migrates the database

        return $this->own ??= new DbalPolicyStore($this->database($implementation));
    }
}
