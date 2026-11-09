<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use YorvoID\PolicyTesting\Console\PolicyTestCommand;
use YorvoID\PolicyTesting\PolicyCases;

/**
 * The policy under test, in an environment of its own, "yorvoid_policy": the application's container
 * with YorvoID's stores in memory, its policy cases collected, and the command that runs them. Enable it
 * in that environment only, never in production's:
 *
 * ```php
 * // config/bundles.php
 * YorvoID\PolicyTesting\Symfony\YorvoIDPolicyTestingBundle::class => ['yorvoid_policy' => true],
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final class YorvoIDPolicyTestingBundle extends Bundle
{
    public const string ENVIRONMENT = 'yorvoid_policy';

    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(PolicyCases::class)->addTag('yorvoid.policy_cases');
        $container->addCompilerPass(new InMemoryStoresPass());
        $container->setDefinition(PolicyTestCommand::class, new Definition(PolicyTestCommand::class)->setFactory([PolicyKernel::class, 'command'])->setArguments([new Reference('kernel')])->addTag('console.command'));
    }
}
