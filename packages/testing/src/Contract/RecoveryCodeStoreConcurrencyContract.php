<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\Race;

/**
 * What every RecoveryCodeStore keeps among requests at the same moment (ATN-025): of several that use the same code, exactly one does. Using it must be one atomic step.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides RecoveryCodeStoreContract:
 *
 * ```php
 * final class MyRecoveryCodeStoreConcurrencyTest extends TestCase
 * {
 *     use RecoveryCodeStoreConcurrencyContract;
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
trait RecoveryCodeStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testRecoveryCodeStoreLetsOneOfManyUseACodeAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 4);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(RecoveryCodeStore::class, $implementation, $test);
        \assert($store instanceof RecoveryCodeStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            $principal = new PrincipalId(new UuidV7());
            $hash = hash('sha256', 'code '.$i);
            $store->replace(new Realm('acme'), $principal, [$hash], new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));
            $rounds[] = ['realm' => 'acme', 'principal' => (string) $principal, 'hash' => $hash];
        }

        Races::assertWinners(1, $race->run('recovery_code.use', $implementation, $test, $rounds), 'not exactly one of the processes used the code.');
    }
}
