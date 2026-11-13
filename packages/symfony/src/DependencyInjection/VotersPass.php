<?php

declare(strict_types=1);

namespace YorvoID\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authorization\Voter\TraceableVoter;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Symfony\Security\AbstainOnYorvoIDAbilities;
use YorvoID\Symfony\Security\PolicyVoter;

/**
 * Wraps every voter Symfony's access decision manager asks, but YorvoID's, so that none can grant a
 * declared ability (M-7). It runs after SecurityBundle has gathered them, whoever tagged them, and wraps
 * each where the manager uses it, not its service: an application's decorator of a voter, whatever its
 * priority, is inside the wrapper. In debug, SecurityBundle's TraceableVoter stays outermost, so the
 * profiler shows the vote that counts.
 *
 * @internal
 */
final class VotersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('security.access.decision_manager')) {
            $container->setParameter('yorvoid.voters.wrapped', []);

            return;
        }

        $manager = $container->getDefinition('security.access.decision_manager');
        $voters = $manager->getArgument(0);
        $wrapped = [];
        $values = [];

        foreach ($voters instanceof IteratorArgument ? $voters->getValues() : [] as $voter) {
            \assert($voter instanceof Reference);
            $traced = $container->getDefinition((string) $voter);
            // In debug, each voter is behind a TraceableVoter of SecurityBundle's: its first argument is the voter.
            $inner = TraceableVoter::class === $traced->getClass() ? $traced->getArgument(0) : $voter;
            \assert($inner instanceof Reference);
            $class = $container->getParameterBag()->resolveValue($container->findDefinition((string) $inner)->getClass());

            if (PolicyVoter::class === $class) {
                $values[] = $voter;

                continue;
            }

            // A service of its own, not a decorator of the voter's, and referred to, so that the manager's list stays lazy.
            $container->setDefinition($id = '.yorvoid.voter.'.$inner, new Definition(AbstainOnYorvoIDAbilities::class, [$inner, new Reference(PolicyCatalogue::class)]));
            $wrapper = new Reference($id);
            $wrapped[] = \is_string($class) ? $class : (string) $inner;

            if ($inner === $voter) {
                $values[] = $wrapper;
            } else {
                $traced->replaceArgument(0, $wrapper);
                $values[] = $voter;
            }
        }

        $manager->replaceArgument(0, new IteratorArgument($values));
        $container->setParameter('yorvoid.voters.wrapped', $wrapped);
    }
}
