<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\VersionMemo;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\Storage\CachedPolicyStore;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Symfony\Migrations;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The bundle keeps what decisions read in a pool of its own, yorvoid.cache, with the installation's ID
 * in every key; the stores decisions read are the cached ones, and they forget between requests and
 * before work that conflicted runs again.
 */
final class CacheTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/cache-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testWhatDecisionsReadIsKeptInYorvoIDsPool(): void
    {
        $container = $this->boot([]);
        self::assertInstanceOf(CachedPolicyStore::class, $container->get('yorvoid.store.policy'));
        self::assertInstanceOf(CachedRelationStore::class, $container->get('yorvoid.store.relation'));
        $installation = $container->get(Installation::class);
        \assert($installation instanceof Installation);
        $pool = $container->get('yorvoid.cache');
        \assert($pool instanceof CacheItemPoolInterface);
        $reads = 0;
        $read = static function () use (&$reads): array {
            ++$reads;

            return ['read'];
        };

        $this->cache($container)->get(new Realm('acme'), 'policy', 1, 'roles', [], $read, \is_array(...));
        self::assertTrue($pool->getItem(\sprintf('yorvoid.v2.%s.acme.policy.1.roles', $installation->id()))->isHit());

        $resetter = $container->get('services_resetter');
        \assert($resetter instanceof ResetInterface);
        $resetter->reset();
        $pool->clear();
        $this->cache($container)->get(new Realm('acme'), 'policy', 1, 'roles', [], $read, \is_array(...));
        self::assertSame(2, $reads, 'The request\'s memo is reset with the container.');
    }

    /**
     * Work that conflicted is decided again on what's committed (D-52): every cache of what decisions
     * read forgets it first, not only the shared memo.
     */
    public function testEveryCacheOfDecisionsForgetsBeforeWorkRunsAgain(): void
    {
        $container = $this->boot([]);
        $transactions = $container->get(DbalTransactions::class);
        \assert($transactions instanceof DbalTransactions);
        $reads = new \ReflectionProperty(DbalTransactions::class, 'reads')->getValue($transactions);
        \assert(\is_array($reads));

        foreach ([VersionedCache::class, 'yorvoid.store.policy', 'yorvoid.store.relation'] as $id) {
            self::assertContains($container->get($id), $reads, $id);
        }
    }

    /**
     * The memo of versions for showing is opt-in, and forgets between requests.
     */
    public function testTheVersionMemoIsOptInAndForgetsBetweenRequests(): void
    {
        $memo = static function (ContainerInterface $container): mixed {
            $evaluator = $container->get(Evaluator::class);
            \assert($evaluator instanceof Evaluator);

            return new \ReflectionProperty(Evaluator::class, 'memo')->getValue($evaluator);
        };

        self::assertNull($memo($this->boot([])), 'Off by default.');
        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove($this->directory);

        $container = $this->boot(['authorization' => ['version_memo' => 'request']]);
        $versions = $memo($container);
        self::assertInstanceOf(VersionMemo::class, $versions);
        $versions->versions(new Realm('acme'), static fn (): array => ['policy' => 1]);
        $resetter = $container->get('services_resetter');
        \assert($resetter instanceof ResetInterface);
        $resetter->reset();
        self::assertSame(['policy' => 2], $versions->versions(new Realm('acme'), static fn (): array => ['policy' => 2]), 'Forgotten at the end of the request.');
    }

    public function testWithoutAPoolARequestStillReadsOnce(): void
    {
        $container = $this->boot(['cache' => ['pool' => null]]);
        $pool = $container->get('yorvoid.cache');
        \assert($pool instanceof CacheItemPoolInterface);
        $reads = 0;
        $read = static function () use (&$reads): array {
            ++$reads;

            return ['read'];
        };

        $this->cache($container)->get(new Realm('acme'), 'policy', 1, 'roles', [], $read, \is_array(...));
        $this->cache($container)->get(new Realm('acme'), 'policy', 1, 'roles', [], $read, \is_array(...));

        self::assertSame(1, $reads);
        self::assertFalse($pool->hasItem(\sprintf('yorvoid.v2.%s.acme.policy.1.roles', $this->installation($container))));
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function boot(array $yorvoid): ContainerInterface
    {
        $this->kernel = new TestKernel($this->directory, $yorvoid);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $database = $container->get(Database::class);
        \assert($database instanceof Database);
        new Migrator($database, Migrations::all())->migrate();

        return $container;
    }

    private function cache(ContainerInterface $container): VersionedCache
    {
        $cache = $container->get(VersionedCache::class);
        \assert($cache instanceof VersionedCache);

        return $cache;
    }

    private function installation(ContainerInterface $container): string
    {
        $installation = $container->get(Installation::class);
        \assert($installation instanceof Installation);

        return $installation->id();
    }
}
