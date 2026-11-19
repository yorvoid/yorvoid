<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Testing\Race;

/**
 * What every PrincipalStore keeps among requests at the same moment: of several that read a principal and then change its status from what they read, exactly one does. The change must check what was read.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides PrincipalStoreContract:
 *
 * ```php
 * final class MyPrincipalStoreConcurrencyTest extends TestCase
 * {
 *     use PrincipalStoreConcurrencyContract;
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
trait PrincipalStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testPrincipalStoreLetsOneOfManyChangeAStatusAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 4);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(PrincipalStore::class, $implementation, $test);
        \assert($store instanceof PrincipalStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');
            $principal = new Principal(new PrincipalId(new UuidV7()), new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
            $store->add($principal);
            $rounds[] = ['realm' => 'acme', 'principal' => (string) $principal->id, 'at' => '2026-10-28 08:00:00 UTC'];
        }

        Races::assertWinners(1, $race->run('principal.change_status', $implementation, $test, $rounds), 'not exactly one of the processes changed the status they all read.');
    }
}
