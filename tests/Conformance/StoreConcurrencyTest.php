<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Testing\Contract\CeremonyStoreConcurrencyContract;
use YorvoID\Testing\Contract\OneTimeTokenStoreConcurrencyContract;
use YorvoID\Testing\Contract\PrincipalStoreConcurrencyContract;
use YorvoID\Testing\Contract\RecoveryCodeStoreConcurrencyContract;
use YorvoID\Testing\Contract\SecurityLogStoreConcurrencyContract;
use YorvoID\Testing\Contract\SessionStoreConcurrencyContract;
use YorvoID\Testing\Contract\SetupStoreConcurrencyContract;
use YorvoID\Testing\Contract\ThrottleStoreConcurrencyContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * YorvoID's own stores pass the concurrency contracts on PostgreSQL. SQLite is for development and tests
 * (storage.md): it lets one writer in at a time, and a second process's append is refused as busy.
 */
final class StoreConcurrencyTest extends DatabaseTestCase
{
    use CeremonyStoreConcurrencyContract;
    use OneTimeTokenStoreConcurrencyContract;
    use PrincipalStoreConcurrencyContract;
    use RecoveryCodeStoreConcurrencyContract;
    use SecurityLogStoreConcurrencyContract;
    use SessionStoreConcurrencyContract;
    use SetupStoreConcurrencyContract;
    use ThrottleStoreConcurrencyContract;

    public static function implementations(): iterable
    {
        yield 'PostgreSQL' => ['postgresql'];
    }

    protected function setUp(): void
    {
        $this->stack('postgresql'); // skipped without PostgreSQL to test against
    }

    protected function storeFactory(): string
    {
        return __DIR__.'/../Support/race-stores.php';
    }
}
