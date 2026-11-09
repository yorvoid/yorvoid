<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Symfony;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Realm;
use YorvoID\Policy\Policy;
use YorvoID\PolicyTesting\Console\PolicyMutateCommand;
use YorvoID\PolicyTesting\Console\PolicyTestCommand;
use YorvoID\PolicyTesting\Mutation\Mutants;
use YorvoID\PolicyTesting\PolicyServices;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\PolicyUnderTest;

/**
 * The policy under test from the application's kernel, in the "yorvoid_policy" environment, where
 * {@see YorvoIDPolicyTestingBundle} is enabled:
 *
 * ```php
 * $policy = PolicyKernel::underTest(new Kernel('yorvoid_policy', false));
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final class PolicyKernel
{
    /**
     * @throws IntegratorError When the kernel doesn't have the bundle
     */
    public static function underTest(KernelInterface $kernel): PolicyUnderTest
    {
        $kernel->boot();
        $container = self::container($kernel);
        $stores = $container->get(Stores::class);
        $mutants = $container->get(Mutants::class);
        \assert($stores instanceof Stores && $mutants instanceof Mutants);

        return new PolicyUnderTest(static function () use ($container, $stores): PolicyServices {
            $stores->empty();
            $operations = $container->get('yorvoid_policy_testing.operations');
            $policy = $container->get('yorvoid_policy_testing.policy');
            $realm = $container->get('yorvoid_policy_testing.realm');
            \assert($operations instanceof Operations && $policy instanceof Policy && $realm instanceof Realm);

            return new PolicyServices($operations, $policy, $realm);
        }, $mutants);
    }

    /**
     * The policy and the application's own cases, from the kernel.
     *
     * @throws IntegratorError When the kernel doesn't have the bundle
     */
    public static function suite(KernelInterface $kernel): PolicySuite
    {
        $policy = self::underTest($kernel);
        $cases = self::container($kernel)->get('yorvoid_policy_testing.cases');
        \assert($cases instanceof CasesOf);

        return new PolicySuite($policy, ...$cases->all);
    }

    /**
     * yorvoid:policy:test, run in the environment it's in.
     *
     * @internal The bundle's factory
     */
    public static function command(KernelInterface $kernel): PolicyTestCommand
    {
        return new PolicyTestCommand(static fn (): PolicySuite => self::suite($kernel));
    }

    /**
     * yorvoid:policy:mutate, run in the environment it's in.
     *
     * @internal The bundle's factory
     */
    public static function mutateCommand(KernelInterface $kernel): PolicyMutateCommand
    {
        return new PolicyMutateCommand(static fn (): PolicySuite => self::suite($kernel));
    }

    private static function container(KernelInterface $kernel): ContainerInterface
    {
        $container = $kernel->getContainer();

        if (!$container->has(Stores::class)) {
            throw new IntegratorError(\sprintf('The kernel in the "%s" environment doesn\'t have YorvoID\'s policy testing bundle.', $kernel->getEnvironment()), \sprintf('Enable YorvoIDPolicyTestingBundle for the "%s" environment in config/bundles.php, and boot the kernel in it.', YorvoIDPolicyTestingBundle::ENVIRONMENT), 'how-to/test-your-policy.md#in-symfony');
        }

        return $container;
    }
}
