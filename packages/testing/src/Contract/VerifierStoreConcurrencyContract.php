<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\IntentVerifier\VerifierState;
use YorvoID\IntentVerifier\VerifierStore;
use YorvoID\Testing\Race;

/**
 * What every VerifierStore keeps among processes at the same moment (INT-009): no process's change is
 * lost. Each reads a count in the state and writes it back one higher; with transactions that take
 * turns, the count ends at the number of changes.
 *
 * Its processes get the store from your factory file (see Race), for VerifierStore::class. Use it
 * besides VerifierStoreContract.
 *
 * @api
 */
trait VerifierStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testVerifierStoreLosesNoChangeMadeAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory());
        $test = bin2hex(random_bytes(6));
        $store = $race->store(VerifierStore::class, $implementation, $test);
        \assert($store instanceof VerifierStore);

        $race->run('verifier.count', $implementation, $test, array_fill(0, Races::ROUNDS, []));

        $store->transaction(static function (VerifierState $state) use ($race): void {
            self::assertSame(str_pad((string) (Races::ROUNDS * $race->processes), 64, '0', \STR_PAD_LEFT), $state->bootstrapSecretHash, 'A change made at the same moment as another was lost.');
        });
    }
}
