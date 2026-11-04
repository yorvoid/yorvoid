<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Flows\Flow;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Symfony\Controller\FlowController;
use YorvoID\Symfony\Factories;
use YorvoID\Symfony\Flow\FlowSource;
use YorvoID\Tests\Symfony\App\TermsCustomizer;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * An application's own flows, next to YorvoID's (BND-11): they keep their own names, and requests
 * reach only what the application opens to them.
 */
final class FlowSourceTest extends TestCase
{
    public function testAddsTheApplicationsFlowsAndOpensOnlyWhatItSays(): void
    {
        self::assertSame(FlowController::ENTRANCES, Factories::entrances([]));
        self::assertSame(FlowController::ENTRANCES + ['acme.terms' => ['version' => 'acme.version']], Factories::entrances([new TermsCustomizer()]));

        $flows = Factories::flows($this->journeys(), [], [new TermsCustomizer()]);
        $names = array_map(static fn (Flow $flow): string => $flow->name, $flows);
        self::assertSame(['acme.terms', 'acme.hidden'], \array_slice($names, -2));
        self::assertContains(PasskeyFlows::SIGN_IN, $names);
    }

    public function testRefusesTheApplicationTakingYorvoIDsNames(): void
    {
        foreach ([[['yorvoid.mine' => []]], [['acme.terms' => ['plan' => 'yorvoid.session']]], [['acme.terms' => ['plan' => 'flows.step']]]] as [$entrances]) {
            try {
                Factories::entrances([self::source([], $entrances)]);
                self::fail('Opened: '.json_encode($entrances));
            } catch (IntegratorError $error) {
                self::assertStringContainsString('gives a request a key of YorvoID\'s', $error->getMessage());
            }
        }

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('names starting with "yorvoid." are YorvoID\'s');
        Factories::flows($this->journeys(), [], [self::source([new Flow('yorvoid.mine', [new TermsCustomizer()])], [])]);
    }

    private function journeys(): PasskeyFlows
    {
        $directory = \sprintf('%s/yorvoid-flow-source-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $kernel = new TestKernel($directory);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            \assert($container instanceof \Symfony\Component\DependencyInjection\ContainerInterface);
            $journeys = $container->get(PasskeyFlows::class);
            \assert($journeys instanceof PasskeyFlows);

            return $journeys;
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($directory);
        }
    }

    /**
     * @param list<Flow>                           $flows
     * @param array<string, array<string, string>> $entrances
     */
    private static function source(array $flows, array $entrances): FlowSource
    {
        return new readonly class($flows, $entrances) implements FlowSource {
            /**
             * @param list<Flow>                           $flows
             * @param array<string, array<string, string>> $entrances
             */
            public function __construct(private array $flows, private array $entrances)
            {
            }

            public function flows(): iterable
            {
                return $this->flows;
            }

            public function entrances(): array
            {
                return $this->entrances;
            }
        };
    }
}
