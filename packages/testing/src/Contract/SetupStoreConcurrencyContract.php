<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Core\Realm;
use YorvoID\Testing\Race;

/**
 * What every SetupStore keeps among requests at the same moment (ATN-034): of several that claim a realm with its code, exactly one does.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides SetupStoreContract:
 *
 * ```php
 * final class MySetupStoreConcurrencyTest extends TestCase
 * {
 *     use SetupStoreConcurrencyContract;
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
trait SetupStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testSetupStoreLetsOneOfManyClaimARealmAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 4);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(SetupStore::class, $implementation, $test);
        \assert($store instanceof SetupStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            $realm = 'realm-'.$i;
            $hash = hash('sha256', 'setup '.$i);
            $store->replaceCode(new Realm($realm), $hash, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'), new \DateTimeImmutable('2126-10-27 18:40:00 UTC'));
            $rounds[] = ['realm' => $realm, 'hash' => $hash, 'at' => '2026-10-27 19:00:00 UTC'];
        }

        Races::assertWinners(1, $race->run('setup.claim', $implementation, $test, $rounds), 'not exactly one of the processes claimed the realm.');
    }
}
