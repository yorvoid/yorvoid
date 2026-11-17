<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\Storage\InMemorySessionStore;
use YorvoID\PolicyTesting\Symfony\InMemoryStoresPass;
use YorvoID\Symfony\DependencyInjection\Stores;
use YorvoID\Tests\Symfony\App\AsksForTheDbalPolicyStore;
use YorvoID\Tests\Symfony\App\AsksForThePolicyStore;
use YorvoID\Tests\Symfony\App\AsksForTheSessionStore;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Stores are for implementing, not calling (AR-01): the bridge registers them under IDs of their own,
 * so an application's service can't autowire one, by its interface or by its class, and an
 * application's store replaces YorvoID's by aliasing its ID.
 */
final class StoreWiringTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/yorvoid-store-wiring-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function askers(): iterable
    {
        yield 'by its interface' => [AsksForThePolicyStore::class];
        yield 'by its class' => [AsksForTheDbalPolicyStore::class];
        yield 'another store' => [AsksForTheSessionStore::class];
    }

    /**
     * @param class-string $asker
     */
    #[DataProvider('askers')]
    public function testAnApplicationsServiceDoesntGetAStore(string $asker): void
    {
        try {
            $this->boot(services: [$asker], aliases: ['test.asker' => $asker]);
            self::fail('An application\'s service was given one of YorvoID\'s stores.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Cannot autowire service "'.$asker.'"', $exception->getMessage());
        }
    }

    public function testAnApplicationsStoreReplacesYorvoIDsByItsId(): void
    {
        $container = $this->boot(services: [InMemorySessionStore::class], aliases: ['yorvoid.store.session' => InMemorySessionStore::class]);

        $sessions = $container->get(Sessions::class);
        self::assertInstanceOf(Sessions::class, $sessions);
        self::assertInstanceOf(InMemorySessionStore::class, new \ReflectionProperty(Sessions::class, 'store')->getValue($sessions), 'YorvoID kept its own store.');
    }

    /**
     * Every store is there under its ID, and nowhere under its interface; with and without the
     * optional parts the bridge leaves out.
     */
    public function testEveryStoreIsThereUnderItsIdOnly(): void
    {
        $everything = ['twig' => true, 'mailer' => true, 'translations' => true];
        // Without device recognition nothing uses the known devices, and the container drops them.
        $little = ['twig' => false, 'mailer' => false, 'translations' => false, 'yorvoid' => ['notifications' => ['enabled' => false], 'sessions' => ['recognise_devices' => false]]];

        foreach ([[$everything, []], [$little, ['yorvoid.store.known_device']]] as [$optional, $unused]) {
            $container = $this->boot(...$optional);

            foreach (Stores::IDS as $interface => $id) {
                if (!\in_array($id, $unused, true)) {
                    self::assertInstanceOf($interface, $container->get($id), $id);
                }

                self::assertFalse($container->has($interface), $interface.' is a service of its own.');
            }

            $this->kernel?->shutdown();
            new Filesystem()->remove($this->directory);
        }
    }

    public function testPolicyTestingPutsInMemoryTheStoresTheBridgeHas(): void
    {
        $ids = array_map(static fn (array $store): string => $store[0], array_diff_key(InMemoryStoresPass::STORES, [\YorvoID\Core\Storage\Transactions::class => true]));

        $bridge = array_diff_key(Stores::IDS, [\YorvoID\Flows\FlowStore::class => true]);
        ksort($bridge);
        ksort($ids);

        self::assertSame($bridge, $ids, 'Policy tests would reach a store on the database, or one the bridge doesn\'t have.');
    }

    /**
     * @param list<class-string>    $services
     * @param array<string, string> $aliases
     * @param array<string, mixed>  $yorvoid
     */
    private function boot(array $services = [], array $aliases = [], bool $twig = true, bool $mailer = true, bool $translations = true, array $yorvoid = []): ContainerInterface
    {
        $this->kernel = new TestKernel($this->directory, $yorvoid, translations: $translations, twig: $twig, mailer: $mailer, services: $services, aliases: $aliases);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }
}
