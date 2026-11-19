<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Testing\Race;
use YorvoID\Throttle\ThrottleStore;

/**
 * What every ThrottleStore keeps among requests at the same moment (ATN-040): of six attempts for a key that allows three, exactly three are counted. The write must check what it moves on from.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides ThrottleStoreContract:
 *
 * ```php
 * final class MyThrottleStoreConcurrencyTest extends TestCase
 * {
 *     use ThrottleStoreConcurrencyContract;
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
trait ThrottleStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testThrottleStoreCountsExactlyTheAttemptsAKeyAllowsAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 6);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(ThrottleStore::class, $implementation, $test);
        \assert($store instanceof ThrottleStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            // An attempt adds 10 ms; the key allows them while it clears at most 20 ms from now: three.
            $rounds[] = ['key' => hash('sha256', 'race '.$i), 'now' => 1_793_210_400_000, 'interval' => 10, 'latest' => 1_793_210_400_020];
        }

        Races::assertWinners(3, $race->run('throttle.advance', $implementation, $test, $rounds), 'not exactly the three attempts the key allows were counted.');
    }
}
