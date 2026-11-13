<?php

declare(strict_types=1);

namespace YorvoID\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Symfony\Security\AbstainOnYorvoIDAbilities;
use YorvoID\Symfony\Security\PolicyVoter;

/**
 * Wraps every voter Symfony's access decision manager asks, but YorvoID's, so that none can grant a
 * declared ability (M-7). It runs after SecurityBundle has gathered them, whoever tagged them; each is
 * decorated, so in debug the profiler's TraceableVoter wraps the wrapper.
 *
 * @internal
 */
final class VotersPass implements CompilerPassInterface
{
    /** In SecurityBundle's debug mode, each voter is behind a TraceableVoter of this ID prefix */
    private const string TRACED = '.debug.security.voter.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('security.access.decision_manager')) {
            $container->setParameter('yorvoid.voters.wrapped', []);

            return;
        }

        $voters = $container->getDefinition('security.access.decision_manager')->getArgument(0);
        $wrapped = [];

        foreach ($voters instanceof IteratorArgument ? $voters->getValues() : [] as $voter) {
            \assert($voter instanceof Reference);
            $id = (string) $voter;
            $id = str_starts_with($id, self::TRACED) ? substr($id, \strlen(self::TRACED)) : $id;
            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());

            if (PolicyVoter::class === $class) {
                continue;
            }

            $container->register($id.'.yorvoid', AbstainOnYorvoIDAbilities::class)
                ->setDecoratedService($id)
                ->setArguments([new Reference($id.'.yorvoid.inner'), new Reference(PolicyCatalogue::class)]);
            $wrapped[] = \is_string($class) ? $class : $id;
        }

        $container->setParameter('yorvoid.voters.wrapped', $wrapped);
    }
}
