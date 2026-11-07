<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Every operation the bundle knows runs through Operations, and only with a permit (D-38): each has
 * one handler, and each handler takes the command, a Permit and the origin, and uses the permit.
 */
final class OperationsWiringTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/operations-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testEveryOperationHasAHandlerThatTakesAPermit(): void
    {
        $container = $this->boot();
        $catalogue = $container->get(OperationCatalogue::class);
        \assert($catalogue instanceof OperationCatalogue);
        self::assertInstanceOf(Operations::class, $container->get(Operations::class));

        // Scenario's own sign-in runs only through Scenario's own Operations.
        self::assertFalse($catalogue->has('yorvoid.testing.sign_in'));
        self::assertGreaterThanOrEqual(30, \count($catalogue->all()));

        foreach ($catalogue->all() as $operation) {
            $handler = $this->handler($container, $operation->command);
            $parameters = $handler->getParameters();
            $types = array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $parameters);

            self::assertSame([$operation->command, Permit::class, Origin::class], $types, \sprintf('%s\'s handler', $operation->name));
            self::assertTrue(is_a($operation->command, Command::class, true));
            self::assertStringContainsString('$permit->use($command)', self::source($handler), \sprintf('%s\'s handler doesn\'t use its permit first.', $operation->name));
        }
    }

    public function testEveryHandlerRefusesAPermitForAnotherCommand(): void
    {
        $container = $this->boot();
        $catalogue = $container->get(OperationCatalogue::class);
        \assert($catalogue instanceof OperationCatalogue);
        $other = new CreateSetupCode(new Realm('acme'), Duration::days(1));

        foreach ($catalogue->all() as $operation) {
            $handler = $this->handler($container, $operation->command)->getClosure();
            // Without its constructor: a handler that read the command before taking its permit fails otherwise.
            $command = new \ReflectionClass($operation->command)->newInstanceWithoutConstructor();

            try {
                $handler($command, Permits::for($other), new Origin(OriginKind::Web));
                self::fail(\sprintf('%s ran with a permit for another command.', $operation->name));
            } catch (IntegratorError $error) {
                self::assertSame('The permit for "yorvoid.setup.create_code" was given with another command.', $error->problem, $operation->name);
            }
        }
    }

    public function testOnlyConsequencesNameNoWayIn(): void
    {
        $catalogue = $this->boot()->get(OperationCatalogue::class);
        \assert($catalogue instanceof OperationCatalogue);
        $consequences = array_keys(array_filter($catalogue->all(), static fn ($operation): bool => [AuthorizedBy::Consequence] === $operation->authorizedBy));

        self::assertSame(['yorvoid.credential.add', 'yorvoid.identifier.verify', 'yorvoid.session.start'], $consequences);
    }

    /**
     * @param class-string $command
     */
    private function handler(ContainerInterface $container, string $command): \ReflectionFunction
    {
        foreach ($this->handlerServices($container) as $service) {
            $method = $service->operations()[$command] ?? null;

            if (null !== $method) {
                return new \ReflectionFunction($method);
            }
        }

        self::fail(\sprintf('Nothing runs %s.', $command));
    }

    /**
     * @return iterable<\YorvoID\Core\Operation\OperationHandler>
     */
    private function handlerServices(ContainerInterface $container): iterable
    {
        $operations = $container->get(Operations::class);
        \assert($operations instanceof Operations);
        $handlers = new \ReflectionProperty(Operations::class, 'handlers')->getValue($operations);
        \assert(\is_array($handlers));

        foreach ($handlers as $method) {
            \assert($method instanceof \Closure);
            $object = new \ReflectionFunction($method)->getClosureThis();
            \assert($object instanceof \YorvoID\Core\Operation\OperationHandler);

            yield $object;
        }
    }

    private static function source(\ReflectionFunction $function): string
    {
        $file = $function->getFileName();
        \assert(\is_string($file));
        $lines = \array_slice(false === ($all = file($file)) ? [] : $all, (int) $function->getStartLine() - 1, (int) $function->getEndLine() - (int) $function->getStartLine() + 1);

        return implode('', $lines);
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
