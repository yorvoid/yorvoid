<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Symfony\Migrations;
use YorvoID\Tests\Symfony\App\FailingInserts;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The response to audit failure end to end (AUD-006), with the database's inserts failing: refusing,
 * neither an ability nor an operation is permitted without its record, and every failure raises the
 * alert through the application's dispatcher.
 */
final class AuditFailureTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    /** @var list<AuditFailed> */
    private array $alerts = [];

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/audit-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        FailingInserts::$tables = [];
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testRefusingNothingIsPermittedWithoutItsRecord(): void
    {
        $container = $this->boot(['audit' => ['on_failure' => 'refuse'], 'decision_log' => ['permits' => 'all', 'connection' => 'audit']]);
        $checker = $container->get('security.authorization_checker');
        \assert($checker instanceof AuthorizationCheckerInterface);
        $operations = $container->get(Operations::class);
        \assert($operations instanceof Operations);

        $store = $container->get(DbalDecisionLogStore::class);
        \assert($store instanceof DbalDecisionLogStore);
        self::assertTrue($store->writesApart(), 'On the connection configured for it.');
        self::assertTrue($checker->isGranted('acme.pages.view'), 'Recorded, it\'s permitted.');

        FailingInserts::$tables = [DbalDecisionLogStore::TABLE];
        self::assertFalse($checker->isGranted('acme.pages.view'), 'Its record can\'t be written: refused.');

        try {
            $operations->run(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com'), new Operator(), new Origin(OriginKind::Console));
            self::fail('It ran.');
        } catch (NotPermitted $refused) {
            self::assertSame([IndeterminateReason::AuditUnavailable], $refused->decision->indeterminate);
        }

        self::assertSame(0, $this->principals($container), 'The handler didn\'t run.');
        self::assertSame([[AuditFailed::DECISION_LOG, true], [AuditFailed::DECISION_LOG, true]], array_map(static fn (AuditFailed $alert): array => [$alert->log, $alert->refused], $this->alerts()));
    }

    public function testDecisionsAreWrittenOnTheApplicationsConnectionByDefault(): void
    {
        $store = $this->boot([])->get(DbalDecisionLogStore::class);
        \assert($store instanceof DbalDecisionLogStore);

        self::assertFalse($store->writesApart());
    }

    public function testAChangeWhoseEntryCantBeWrittenRollsBackInEveryMode(): void
    {
        $container = $this->boot([]);
        $operations = $container->get(Operations::class);
        \assert($operations instanceof Operations);
        FailingInserts::$tables = [DbalSecurityLogStore::TABLE];

        try {
            $operations->run(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com'), new Operator(), new Origin(OriginKind::Console));
            self::fail('It was committed.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('Injected', $e->getMessage());
        }

        self::assertSame(0, $this->principals($container), 'Rolled back with its entry.');
        self::assertSame([AuditFailed::SECURITY_LOG], array_map(static fn (AuditFailed $alert): string => $alert->log, $this->alerts()));
    }

    public function testContinuingAnAbilityGoesAheadAndTheAlertIsRaisedAtTheEnd(): void
    {
        $container = $this->boot([]);
        $checker = $container->get('security.authorization_checker');
        \assert($checker instanceof AuthorizationCheckerInterface);
        FailingInserts::$tables = [DbalDecisionLogStore::TABLE];

        self::assertFalse($checker->isGranted('acme.pages.publish'), 'A refusal, waiting for the end of the request.');
        self::assertCount(0, $this->alerts);

        $resetter = $this->kernel?->getContainer()->get('services_resetter');
        \assert($resetter instanceof ResetInterface);
        $resetter->reset();
        self::assertSame([[AuditFailed::DECISION_LOG, false]], array_map(static fn (AuditFailed $alert): array => [$alert->log, $alert->refused], $this->alerts()));
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
        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(AuditFailed::class, function (AuditFailed $alert): void {
            $this->alerts[] = $alert;
        });

        return $container;
    }

    /**
     * The alerts raised so far.
     *
     * @return list<AuditFailed>
     */
    private function alerts(): array
    {
        return $this->alerts;
    }

    private function principals(ContainerInterface $container): int
    {
        $database = $container->get(Database::class);
        \assert($database instanceof Database);

        $count = $database->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $database->table(DbalPrincipalStore::TABLE)));
        \assert(\is_int($count) || \is_string($count));

        return (int) $count;
    }
}
