<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Race;

/**
 * What every SecurityLogStore keeps among processes at the same moment (PLT-025): appends never fork a
 * realm's chain, each entry gets its own number and links to the one before, and lock() makes changes
 * that depend on what the realm holds take turns.
 *
 * Its processes get their stores from your factory file (see Race): the store, and the transactions it
 * takes part in (Transactions::class), on one connection in each process. A store in memory can't run
 * it. Use it besides SecurityLogStoreContract:
 *
 * ```php
 * final class MySecurityLogStoreConcurrencyTest extends TestCase
 * {
 *     use SecurityLogStoreConcurrencyContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function storeFactory(): string
 *     {
 *         return __DIR__.'/race-stores.php';
 *     }
 * }
 * ```
 *
 * @api
 */
trait SecurityLogStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testSecurityLogStoreNeverForksAChainUnderAppendsAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory());
        $test = bin2hex(random_bytes(6));
        $store = $race->store(SecurityLogStore::class, $implementation, $test);
        \assert($store instanceof SecurityLogStore);

        $sequences = array_merge(...$race->run('security_log.append', $implementation, $test, array_fill(0, Races::ROUNDS, ['realm' => 'acme'])));
        sort($sequences);
        self::assertSame(range(1, Races::ROUNDS * $race->processes), $sequences, 'Two appends got the same number, or a number was skipped.');

        $previous = null;

        foreach ($store->entries(new Realm('acme'), 1) as $entry) {
            self::assertSame($previous ?? 'genesis', $entry->previousHash, \sprintf('Entry %d doesn\'t link to the one before it.', $entry->sequence));
            $previous = $entry->hash;
        }
    }

    #[DataProvider('implementations')]
    public function testSecurityLogStoreLocksARealmForOneTransactionAtATime(string $implementation): void
    {
        $race = new Race($this->storeFactory());
        $test = bin2hex(random_bytes(6));
        $race->store(Transactions::class, $implementation, $test);

        foreach ($race->run('security_log.lock', $implementation, $test, array_fill(0, 5, ['realm' => 'acme'])) as $round => $counts) {
            $counts = array_map(static fn (mixed $count): int => \is_int($count) ? $count : -1, $counts);
            self::assertCount(\count($counts), array_unique($counts), \sprintf('Round %d: two transactions under the realm\'s lock read the same count of entries; lock() didn\'t make them take turns.', $round + 1));
        }
    }
}
