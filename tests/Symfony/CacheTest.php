<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\CachedPolicyStore;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Symfony\Migrations;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The bundle keeps what decisions read in a pool of its own, yorvoid.cache, with the installation's ID
 * in every key; the stores decisions read are the cached ones, and they forget between requests.
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
        self::assertInstanceOf(CachedPolicyStore::class, $container->get(PolicyStore::class));
        self::assertInstanceOf(CachedRelationStore::class, $container->get(RelationStore::class));
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
