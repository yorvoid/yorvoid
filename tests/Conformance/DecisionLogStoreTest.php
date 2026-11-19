<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\DecisionLogStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Also with a connection of its own for writing (decision_log.connection): what it writes inside a
 * caller's transaction is written apart, and listed for a digest.
 */
final class DecisionLogStoreTest extends DatabaseTestCase
{
    use DecisionLogStoreContract;

    public static function implementations(): iterable
    {
        yield from parent::implementations();
        yield 'PostgreSQL, writing apart' => ['postgresql apart'];
    }

    protected function decisionLogStore(string $implementation): DecisionLogStore
    {
        if ('postgresql apart' !== $implementation) {
            return $this->stackOf($implementation)->decisions;
        }

        $database = $this->stackOf('postgresql')->database;
        \assert($database instanceof Database);

        return new DbalDecisionLogStore($database, $this->database('postgresql')->connection);
    }

    protected function transactions(string $implementation): Transactions
    {
        return $this->stackOf('postgresql apart' === $implementation ? 'postgresql' : $implementation)->transactions;
    }
}
