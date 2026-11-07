<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Symfony\Migrations;
use YorvoID\Tests\Core\Authorization\Fixtures\UpdateSite;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * isGranted() for every declared ability, decided by YorvoID's evaluator; anything else stays the
 * application's voters' to decide.
 */
final class PolicyVoterTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/voter-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testDecidesTheDeclaredAbilitiesForWhoeverIsntSignedIn(): void
    {
        $container = $this->boot();
        $database = $container->get(Database::class);
        \assert($database instanceof Database);
        new Migrator($database, Migrations::all())->migrate();
        $checker = $container->get('security.authorization_checker');
        \assert($checker instanceof AuthorizationCheckerInterface);
        $catalogue = $container->get(PolicyCatalogue::class);
        \assert($catalogue instanceof PolicyCatalogue);

        self::assertTrue($catalogue->has('yorvoid.principals.manage'), 'YorvoID declares its own.');
        self::assertTrue($catalogue->has('acme.pages.view'), 'The application\'s are collected.');
        self::assertTrue($checker->isGranted('acme.pages.view'));
        self::assertTrue($checker->isGranted('acme.pages.view', new ResourceRef('page', '1')));
        self::assertFalse($checker->isGranted('acme.pages.publish', new ResourceRef('page', '1')));
        self::assertFalse($checker->isGranted('acme.sites.update', new UpdateSite(new Realm('default'), '7')));
        self::assertFalse($checker->isGranted('yorvoid.principals.manage'));
        self::assertInstanceOf(Policy::class, $container->get(Policy::class));
    }

    private function boot(): ContainerInterface
    {
        $this->kernel = new TestKernel($this->directory);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }
}
