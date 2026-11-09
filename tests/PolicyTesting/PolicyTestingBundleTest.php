<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\PolicyTesting\Console\PolicyTestCommand;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyCases;
use YorvoID\PolicyTesting\PolicyResult;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\PolicyTests;
use YorvoID\PolicyTesting\Symfony\PolicyKernel;
use YorvoID\PolicyTesting\Symfony\Stores;
use YorvoID\PolicyTesting\Symfony\YorvoIDPolicyTestingBundle;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\PolicyTesting\Fixtures\AcmeCases;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The policy under test in Symfony: the application's own container in the "yorvoid_policy"
 * environment, with only YorvoID's stores in memory, and yorvoid:policy:test.
 */
final class PolicyTestingBundleTest extends TestCase
{
    private string $directory;

    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/policy-testing-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        new Filesystem()->remove($this->directory);
    }

    /**
     * M2-5's one construction path: production's evaluator, element for element; only the stores differ.
     */
    public function testDecidesWithProductionsElementsOnStoresInMemory(): void
    {
        $production = $this->kernel('prod');
        $underTest = $this->kernel(YorvoIDPolicyTestingBundle::ENVIRONMENT);
        $elements = static fn (TestKernel $kernel): array => array_map(static fn (PolicyElement $element): string => $element::class, self::elements($kernel));

        self::assertSame($elements($production), $elements($underTest));
        self::assertContains('YorvoID\Tests\Symfony\App\LockedPages', $elements($underTest), 'The application\'s own.');
        self::assertInstanceOf(InMemoryPolicyStore::class, self::service($underTest, PolicyStore::class));
        self::assertInstanceOf(InMemoryPrincipalStore::class, self::service($underTest, PrincipalStore::class));
        self::assertNotInstanceOf(InMemoryPolicyStore::class, self::service($production, PolicyStore::class), 'Only in its environment.');
        self::assertTrue($underTest->getContainer()->has(Transactions::class), 'As public as the bridge made it…');
        self::assertFalse($underTest->getContainer()->has(PolicyStore::class), '…and as private.');
    }

    /**
     * A store the pass misses fails the tests: YorvoID's connection isn't there to reach a database.
     */
    public function testNeverReachesTheDatabase(): void
    {
        $underTest = $this->kernel(YorvoIDPolicyTestingBundle::ENVIRONMENT);
        $results = new PolicyTests()->run(PolicyKernel::underTest($underTest), new AcmeCases()->cases());

        self::assertSame([], array_values(array_filter(array_map(static fn (PolicyResult $result): ?string => $result->failure, $results), static fn (?string $failure): bool => null !== $failure)), 'The sample\'s every case, without it.');
        self::assertFileDoesNotExist($this->directory.'/yorvoid.sqlite');

        try {
            self::service($underTest, Database::class);
            self::fail('The database was reached.');
        } catch (\LogicException $refused) {
            self::assertStringStartsWith('Policy tests never use the database: something asked for YorvoID\'s connection', $refused->getMessage());
        }
    }

    public function testAWrongCaseFails(): void
    {
        $policy = PolicyKernel::underTest($this->kernel(YorvoIDPolicyTestingBundle::ENVIRONMENT));
        $result = new PolicyTests()->one($policy, PolicyCase::that('a member publishes')->given(roles: ['member'])->asks('acme.pages.publish', new ResourceRef('page', '1'))->permits());

        self::assertSame('Expected permit, got deny: None of their roles has "acme.pages.publish". (a member publishes)', $result->failure);
    }

    public function testBootsTheKernelAndCollectsItsCases(): void
    {
        $kernel = new TestKernel($this->directory, environment: YorvoIDPolicyTestingBundle::ENVIRONMENT);
        $this->kernels[] = $kernel;
        $suite = PolicyKernel::suite($kernel);

        self::assertSame([AcmeCases::class], array_map(static fn (PolicyCases $cases): string => $cases::class, $suite->cases));
        self::assertCount(17, $suite->all());
    }

    public function testEmptiesTheStoresAndResetsTheServicesForEachCase(): void
    {
        $storage = new InMemoryStorage();
        $resetter = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $stores = new Stores($storage, $resetter);
        $storage->policy->saveRole(new Realm('acme'), 'reviewer', 'Reviewer');
        $stores->empty();

        self::assertSame([], $storage->policy->roles(new Realm('acme')));
        self::assertSame(1, $resetter->resets);
        self::assertSame($storage->policy, Stores::store($storage, 'policy'));
        $this->expectExceptionMessage('InMemoryStorage has no store "nothing".');
        Stores::store($storage, 'nothing');
    }

    public function testNeedsTheBundle(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The kernel in the "test" environment doesn\'t have YorvoID\'s policy testing bundle.');
        PolicyKernel::underTest($this->kernel('test'));
    }

    public function testTheCommandRunsTheApplicationsCases(): void
    {
        $application = new Application($this->kernel(YorvoIDPolicyTestingBundle::ENVIRONMENT));
        $tester = new CommandTester($application->find('yorvoid:policy:test'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('✔ An editor publishes', $tester->getDisplay());
        self::assertStringContainsString('17 cases, 0 failed.', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame([17, 0], [$report['passed'] ?? null, $report['failed'] ?? null]);
        self::assertIsArray($report['cases'] ?? null);
        self::assertSame(['description' => 'Anyone views pages', 'passed' => true, 'expected' => 'permit', 'verdict' => 'permit', 'failure' => null], $report['cases'][0] ?? null);

        self::assertSame(2, $tester->execute(['--format' => 'xml']), 'Invalid.');
        self::assertStringContainsString('The format "xml" is neither text nor json.', $tester->getDisplay());
    }

    public function testTheCommandFailsWhenACaseDoes(): void
    {
        $kernel = $this->kernel(YorvoIDPolicyTestingBundle::ENVIRONMENT);
        $cases = new readonly class implements PolicyCases {
            public function cases(): iterable
            {
                yield PolicyCase::that('passes')->anonymous()->asks('acme.pages.view')->permits();
                yield PolicyCase::that('fails/é')->anonymous()->asks('acme.pages.publish')->permits();
                yield PolicyCase::that('isn\'t set up')->given(roles: ['nobody'])->asks('acme.pages.publish')->permits();
            }
        };
        $tester = new CommandTester(new PolicyTestCommand(static fn (): PolicySuite => new PolicySuite(PolicyKernel::underTest($kernel), $cases)));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('✘ Expected permit, got deny', $tester->getDisplay());
        self::assertStringContainsString('3 cases, 2 failed.', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['--format' => 'json']));
        $json = $tester->getDisplay();
        self::assertStringContainsString("\n    \"cases\": [", $json, 'Pretty.');
        self::assertStringContainsString('"description": "fails/é"', $json, 'Slashes and Unicode as they are.');
        self::assertStringContainsString('"passed": 1,', $json);
        self::assertStringContainsString('"failed": 2', $json);
        self::assertStringContainsString('"expected": "permit",'."\n".'            "verdict": null,', $json, 'Not decided: it couldn\'t be set up.');
    }

    /**
     * @return list<PolicyElement>
     */
    private static function elements(TestKernel $kernel): array
    {
        $elements = new \ReflectionProperty(Evaluator::class, 'elements')->getValue(self::service($kernel, Evaluator::class));
        \assert(\is_array($elements));

        return array_values(array_filter($elements, static fn (mixed $element): bool => $element instanceof PolicyElement));
    }

    private static function service(TestKernel $kernel, string $id): object
    {
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container->get($id);
    }

    private function kernel(string $environment): TestKernel
    {
        $kernel = new TestKernel($this->directory, environment: $environment);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }
}
