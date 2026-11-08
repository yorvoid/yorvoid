<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\Row;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Symfony\Migrations;
use YorvoID\Symfony\Security\PolicyVoter;
use YorvoID\Tests\Core\Authorization\Fixtures\UpdateSite;
use YorvoID\Tests\Symfony\App\LockedPage;
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

        // The application's own forbid and rule are the evaluator's too.
        self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', 'locked')));
        self::assertFalse($checker->isGranted('acme.pages.view', new LockedPage()), 'A subject that says which resource it concerns.');
        self::assertTrue($checker->isGranted('acme.pages.view_drafts', new ResourceRef('page', 'help')));
        self::assertFalse($checker->isGranted('acme.pages.view_drafts', new ResourceRef('page', 'other')));

        // Refusals are recorded when the request ends; permits of abilities aren't, by default.
        $table = $database->table(DbalDecisionLogStore::TABLE);
        self::assertSame([], $database->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s', $table)), 'They wait for the end of the request.');
        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->dispatch(new TerminateEvent($this->kernel ?? throw new \LogicException('Not booted.'), new Request(), new Response()), KernelEvents::TERMINATE);
        $recorded = array_map(static fn (array $row): array => [Row::string($row, 'name'), Row::string($row, 'verdict'), DecisionRecord::fromJson(Row::string($row, 'content'))], $database->connection->fetchAllAssociative(\sprintf('SELECT name, verdict, content FROM %s ORDER BY name', $table)));
        self::assertSame(['acme.pages.publish', 'acme.pages.view', 'acme.pages.view', 'acme.pages.view_drafts', 'acme.sites.update', 'yorvoid.principals.manage'], array_column($recorded, 0));
        self::assertSame(['deny'], array_values(array_unique(array_column($recorded, 1))));
        self::assertSame(['policy', 'relations'], array_keys($recorded[0][2]->versions), 'Each records the versions it read.');

        $voter = $container->get(PolicyVoter::class);
        \assert($voter instanceof PolicyVoter);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), null, ['acme.pages.view']), 'Without a vote to explain.');
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_ADMIN']), 'Not a declared ability: the application\'s voters decide.');
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
