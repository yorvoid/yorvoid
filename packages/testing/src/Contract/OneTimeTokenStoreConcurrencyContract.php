<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\Race;

/**
 * What every OneTimeTokenStore keeps among requests at the same moment (ATN-030): of several that found the token before any used it, exactly one deletes it. Deleting must be the check.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides OneTimeTokenStoreContract:
 *
 * ```php
 * final class MyOneTimeTokenStoreConcurrencyTest extends TestCase
 * {
 *     use OneTimeTokenStoreConcurrencyContract;
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
trait OneTimeTokenStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testOneTimeTokenStoreGivesATokenToOneOfManyAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 4);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(OneTimeTokenStore::class, $implementation, $test);
        \assert($store instanceof OneTimeTokenStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            $token = new OneTimeToken(bin2hex(random_bytes(16)), new Realm('acme'), new PrincipalId(new UuidV7()), 'acme.race', null, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'), new \DateTimeImmutable('2126-10-27 18:40:00 UTC'));
            $store->add($token, str_repeat('a', 64));
            $rounds[] = ['realm' => 'acme', 'selector' => $token->selector, 'purpose' => 'acme.race'];
        }

        Races::assertWinners(1, $race->run('one_time_token.delete', $implementation, $test, $rounds), 'not exactly one of the processes deleted the token they all found.');
    }
}
