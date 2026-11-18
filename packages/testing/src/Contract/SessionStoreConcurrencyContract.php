<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\Race;

/**
 * What every SessionStore keeps among requests at the same moment: of several that end a session, exactly one does, so its end is recorded once.
 *
 * Its processes get their stores from your factory file (see Race), so it needs a database processes
 * share: a store in memory can't run it. Use it besides SessionStoreContract:
 *
 * ```php
 * final class MySessionStoreConcurrencyTest extends TestCase
 * {
 *     use SessionStoreConcurrencyContract;
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
trait SessionStoreConcurrencyContract
{
    /**
     * Your factory file (see Race): it gives every process the stores of a test.
     */
    abstract protected function storeFactory(): string;

    #[DataProvider('implementations')]
    public function testSessionStoreLetsOneOfManyEndASessionAtOnce(string $implementation): void
    {
        $race = new Race($this->storeFactory(), 4);
        $test = bin2hex(random_bytes(6));
        $store = $race->store(SessionStore::class, $implementation, $test);
        \assert($store instanceof SessionStore);
        $rounds = [];

        for ($i = 0; $i < Races::ROUNDS; ++$i) {
            $started = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');
            $later = new \DateTimeImmutable('2126-10-27 18:40:00 UTC');
            $session = new Session(new SessionId(new UuidV7()), new Realm('acme'), new PrincipalId(new UuidV7()), 1, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $started)), $started, $started, $later, $later);
            $store->add($session, hash('sha256', 'race '.$i.' '.$test));
            $rounds[] = ['realm' => 'acme', 'session' => (string) $session->id, 'at' => '2026-10-27 19:00:00 UTC'];
        }

        Races::assertWinners(1, $race->run('session.end', $implementation, $test, $rounds), 'not exactly one of the processes ended the session.');
    }
}
