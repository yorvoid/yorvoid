<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Testing\Contract\SessionStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every SessionStore keeps the same promises (PLT-025).
 */
final class SessionStoreTest extends DatabaseTestCase
{
    use SessionStoreContract;

    #[DataProvider('tamperedAuthentications')]
    public function testRefusesAnAuthenticationItDidntWrite(string $platform, string $json): void
    {
        $stack = $this->stack($platform);
        \assert(null !== $stack->database);
        $session = self::session(new Realm('acme'), new PrincipalId(new UuidV7()));
        $stack->sessions->add($session, self::hash('a'));
        $stack->database->connection->executeStatement(\sprintf('UPDATE %s SET authentication = ?', $stack->database->table(DbalSessionStore::TABLE)), [$json]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('A session\'s authentication isn\'t what YorvoID wrote: the row was changed.');

        $stack->sessions->get(new Realm('acme'), $session->id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tamperedAuthentications(): iterable
    {
        foreach (self::platforms() as $name => [$platform]) {
            yield $name.', not a list' => [$platform, '"passkey"'];
            yield $name.', a factor that isn\'t an object' => [$platform, '[5]'];
            yield $name.', a method that isn\'t text' => [$platform, '[{"method": 1, "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true, "operation": null}]'];
            yield $name.', a time that isn\'t text' => [$platform, '[{"method": "passkey", "at": 1793731200, "multi_factor": true, "phishing_resistant": true, "operation": null}]'];
            yield $name.', no method' => [$platform, '[{"at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true}]'];
            yield $name.', no time' => [$platform, '[{"method": "passkey", "multi_factor": true, "phishing_resistant": true}]'];
            yield $name.', no strength' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "phishing_resistant": true}]'];
            yield $name.', a strength that isn\'t yes or no' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": 1, "operation": null}]'];
            yield $name.', no operation' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true}]'];
            yield $name.', an operation that isn\'t text' => [$platform, '[{"method": "passkey", "at": "2026-10-27T18:40:00+00:00", "multi_factor": true, "phishing_resistant": true, "operation": 7}]'];
        }
    }

    protected function sessionStore(string $implementation): SessionStore
    {
        return $this->stackOf($implementation)->sessions;
    }
}
