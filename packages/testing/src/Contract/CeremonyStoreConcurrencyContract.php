<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Ceremony;
use YorvoID\Passkeys\CeremonyKind;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Testing\Race;

/**
 * What every CeremonyStore keeps among requests at the same moment: of several that take a ceremony, exactly one gets it, so a WebAuthn answer works once.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides CeremonyStoreContract:
 *
 * ```php
 * final class MyCeremonyStoreConcurrencyTest extends TestCase
 * {
 *     use CeremonyStoreConcurrencyContract;
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
trait CeremonyStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testCeremonyStoreGivesACeremonyToOneOfManyAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 4);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(CeremonyStore::class, $implementation, $test);
        \assert($store instanceof CeremonyStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            $ceremony = new Ceremony(Uuid::v7(), new Realm('acme'), CeremonyKind::SignIn, '{}', new \DateTimeImmutable('2126-10-27 18:40:00 UTC'));
            $store->add($ceremony);
            $rounds[] = ['realm' => 'acme', 'ceremony' => (string) $ceremony->id];
        }

        Races::assertWinners(1, $race->run('ceremony.take', $implementation, $test, $rounds), 'not exactly one of the processes took the ceremony.');
    }
}
