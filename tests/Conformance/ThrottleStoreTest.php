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
    public function testReplacesOnlyWhatItExpects(string $implementation): void
    {
        $store = $this->stack($implementation)->throttle;
        self::assertNull($store->clearAt('a'));

        self::assertTrue($store->replace('a', null, self::NOW));
        self::assertFalse($store->replace('a', null, self::NOW + 5), 'A key was created twice.');
        self::assertFalse($store->replace('a', self::NOW + 1, self::NOW + 5), 'A key changed although it wasn\'t what was expected.');
        self::assertSame(self::NOW, $store->clearAt('a'));
        self::assertTrue($store->replace('a', self::NOW, self::NOW + 5));
        self::assertSame(self::NOW + 5, $store->clearAt('a'));
        self::assertFalse($store->replace('b', self::NOW + 5, self::NOW + 9), 'A key that doesn\'t exist was changed.');
        self::assertNull($store->clearAt('b'));

        $store->remove('a');
        self::assertNull($store->clearAt('a'));
        self::assertTrue($store->replace('a', null, self::NOW + 7));
    }

    #[DataProvider('implementations')]
    public function testPurgesKeysClearAtOrBeforeTheMoment(string $implementation): void
    {
        $store = $this->stack($implementation)->throttle;
        $store->replace('before', null, self::NOW - 1);
        $store->replace('at', null, self::NOW);
        $store->replace('after', null, self::NOW + 1);

        self::assertSame(2, $store->purge(self::NOW));
        self::assertSame([null, null, self::NOW + 1], [$store->clearAt('before'), $store->clearAt('at'), $store->clearAt('after')]);
    }
}
