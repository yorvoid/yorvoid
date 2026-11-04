<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every ThrottleStore keeps the same promises (PLT-025).
 */
final class ThrottleStoreTest extends DatabaseTestCase
{
    /** Milliseconds in 2026: beyond 32 bits */
    private const int NOW = 1_793_210_400_000;

    #[DataProvider('implementations')]
    public function testAdvancesWhileTheKeyAllowsAnotherAttempt(string $implementation): void
    {
        $store = $this->stack($implementation)->throttle;
        self::assertNull($store->clearAt('a'));

        // An attempt adds 10 ms; one is allowed while the key clears at most 20 ms from now.
        self::assertSame(self::NOW + 10, $store->advance('a', self::NOW, 10, self::NOW + 20));
        self::assertSame(self::NOW + 20, $store->advance('a', self::NOW, 10, self::NOW + 20));
        self::assertSame(self::NOW + 30, $store->advance('a', self::NOW, 10, self::NOW + 20));
        self::assertNull($store->advance('a', self::NOW, 10, self::NOW + 20), 'An attempt over the limit was counted.');
        self::assertSame(self::NOW + 30, $store->clearAt('a'), 'A refused attempt changed the key.');

        // Later, from the key's clear time or from now, whichever is later.
        self::assertSame(self::NOW + 40, $store->advance('a', self::NOW + 15, 10, self::NOW + 35));
        self::assertSame(self::NOW + 110, $store->advance('a', self::NOW + 100, 10, self::NOW + 120));
        self::assertNull($store->clearAt('b'), 'Another key changed.');

        $store->remove('a');
        self::assertNull($store->clearAt('a'));
        self::assertSame(self::NOW + 7, $store->advance('a', self::NOW, 7, self::NOW));
        self::assertNull($store->advance('c', self::NOW, 7, self::NOW - 1), 'A new key was counted beyond its limit.');
        self::assertNull($store->clearAt('c'));
    }

    #[DataProvider('implementations')]
    public function testPurgesKeysClearAtOrBeforeTheMoment(string $implementation): void
    {
        $store = $this->stack($implementation)->throttle;
        $store->advance('before', self::NOW - 2, 1, self::NOW);
        $store->advance('at', self::NOW - 1, 1, self::NOW);
        $store->advance('after', self::NOW, 1, self::NOW);

        self::assertSame(2, $store->purge(self::NOW));
        self::assertSame([null, null, self::NOW + 1], [$store->clearAt('before'), $store->clearAt('at'), $store->clearAt('after')]);
    }
}
