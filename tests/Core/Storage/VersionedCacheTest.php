<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Tests\Core\Storage\Fixtures\FailingPool;

/**
 * What decisions read, kept against the version (AUZ-031, D-10): once a request, shared through a pool
 * by servers of one installation, never by two installations or two realms (QA-015), and passed over
 * when the pool fails (OPS-041).
 */
final class VersionedCacheTest extends TestCase
{
    private int $reads = 0;

    public function testARequestReadsOnce(): void
    {
        $cache = new VersionedCache();
        $realm = new Realm('acme');

        self::assertSame(['a'], $this->get($cache, $realm, 1, ['a']));
        self::assertSame(['a'], $this->get($cache, $realm, 1, ['b']), 'Read once for the request.');
        self::assertSame(1, $this->reads);
        self::assertSame(['c'], $this->get($cache, $realm, 2, ['c']), 'Another version is another read.');

        $cache->reset();
        self::assertSame(['d'], $this->get($cache, $realm, 1, ['d']), 'Without a pool, the next request reads again.');
        self::assertSame(3, $this->reads);
    }

    public function testServersShareWhatTheyRead(): void
    {
        $pool = new ArrayAdapter();
        $realm = new Realm('acme');
        $installation = Installation::named('one');
        $this->get(new VersionedCache($pool, $installation), $realm, 7, ['roles']);

        self::assertSame(['roles'], $this->get(new VersionedCache($pool, $installation), $realm, 7, ['other']), 'Another server reads it from the pool.');
        self::assertSame(1, $this->reads);
        self::assertSame(['new'], $this->get(new VersionedCache($pool, $installation), $realm, 8, ['new']), 'A change makes a new version: the old entry is never read again.');
        self::assertSame(['yorvoid.v1.one.acme.policy.7.what', 'yorvoid.v1.one.acme.policy.8.what'], array_keys($pool->getValues()), 'Installation first, then realm, area, version and what.');
    }

    public function testInstallationsAndRealmsNeverReadEachOthers(): void
    {
        $pool = new ArrayAdapter();
        $default = Realm::default();
        $this->get(new VersionedCache($pool, Installation::named('one')), $default, 1, ['one\'s']);

        self::assertSame(['two\'s'], $this->get(new VersionedCache($pool, Installation::named('two')), $default, 1, ['two\'s']), 'Both have a realm "default" at version 1.');
        self::assertSame(['acme\'s'], $this->get(new VersionedCache($pool, Installation::named('one')), new Realm('acme'), 1, ['acme\'s']));
        self::assertSame(3, $this->reads);
    }

    public function testWhatsReadIsHashedWhenAKeyCantHoldIt(): void
    {
        $pool = new ArrayAdapter();
        $cache = new VersionedCache($pool, Installation::named('one'));
        $read = static fn (): array => ['x'];
        $cache->get(new Realm('acme'), 'relations', 1, 'subjects.page:1#editor', $read, \is_array(...));
        $cache->get(new Realm('acme'), 'relations', 1, str_repeat('a', 81), $read, \is_array(...));
        $cache->get(new Realm('acme'), 'relations', 1, str_repeat('a', 80), $read, \is_array(...));
        $cache->get(new Realm('acme'), 'relations', 1, "roles\n", $read, \is_array(...));

        self::assertSame([
            'yorvoid.v1.one.acme.relations.1.'.hash('sha256', 'subjects.page:1#editor'),
            'yorvoid.v1.one.acme.relations.1.'.hash('sha256', str_repeat('a', 81)),
            'yorvoid.v1.one.acme.relations.1.'.str_repeat('a', 80),
            'yorvoid.v1.one.acme.relations.1.'.hash('sha256', "roles\n"),
        ], array_keys($pool->getValues()));
    }

    public function testWhatThePoolHoldsIsReadOnlyIfItsValid(): void
    {
        $pool = new ArrayAdapter();
        $installation = Installation::named('one');
        $realm = new Realm('acme');
        $pool->save($pool->getItem('yorvoid.v1.one.acme.policy.1.what')->set('not a list'));

        self::assertSame(['read'], $this->get(new VersionedCache($pool, $installation), $realm, 1, ['read']));
        self::assertSame(['read'], $this->get(new VersionedCache($pool, $installation), $realm, 1, ['again']), 'Read again, and kept in its place.');
        self::assertSame(1, $this->reads);
    }

    public function testAPoolThatFailsIsPassedOverUntilTheRequestEnds(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                \assert(\is_string($level) && \is_string($context['message'] ?? null));
                $this->messages[] = $level.': '.str_replace('{message}', $context['message'], (string) $message);
            }

            public function count(): int
            {
                return \count($this->messages);
            }
        };
        $pool = new FailingPool();
        $cache = new VersionedCache($pool, Installation::named('one'), $logger);
        $realm = new Realm('acme');

        self::assertSame(['a'], $this->get($cache, $realm, 1, ['a']));
        self::assertSame(['b'], $this->get($cache, $realm, 2, ['b']));
        self::assertSame(['a'], $this->get($cache, $realm, 1, ['c']), 'What it read stays read.');
        self::assertSame(1, $pool->calls, 'Once it failed, the request doesn\'t ask it again.');
        self::assertCount(1, $logger->messages);
        self::assertSame('warning: YorvoID\'s cache failed, so it reads from the stores until the request ends: The pool is down.', $logger->messages[0]);

        $cache->reset();
        $this->get($cache, $realm, 1, ['d']);
        self::assertSame(2, $pool->calls, 'The next request tries it again.');
        self::assertSame(2, $logger->count());

        $saving = new FailingPool(failSave: true);
        $cache = new VersionedCache($saving, Installation::named('one'), $logger);
        self::assertSame(['e'], $this->get($cache, $realm, 1, ['e']), 'A pool that can\'t save is passed over too.');
        $this->get($cache, $realm, 2, ['f']);
        self::assertSame(1, $saving->calls, 'Nor asked again.');
        self::assertSame(3, $logger->count());
    }

    public function testAPoolNeedsTheInstallation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A shared pool needs the installation, which its keys start with.');

        new VersionedCache(new ArrayAdapter());
    }

    /**
     * @param list<string> $value
     *
     * @return list<string>
     */
    private function get(VersionedCache $cache, Realm $realm, int $version, array $value): array
    {
        return $cache->get($realm, 'policy', $version, 'what', function () use ($value): array {
            ++$this->reads;

            return $value;
        }, static fn (mixed $read): bool => \is_array($read) && array_is_list($read));
    }
}
