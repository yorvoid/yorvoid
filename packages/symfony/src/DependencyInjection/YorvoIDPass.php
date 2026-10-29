<?php

declare(strict_types=1);

namespace YorvoID\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use YorvoID\Symfony\Doctor\MailerCheck;

/**
 * Wires what depends on the rest of the application: Symfony's translator when there is one, YorvoID's
 * own otherwise.
 *
 * @internal
 */
final class YorvoIDPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias('yorvoid.translator.used', $container->has('translator') ? 'translator' : 'yorvoid.translator');

        // The doctor checks the mailer's DSNs, as FrameworkBundle configured them; it never prints them.
        if ($container->hasDefinition('mailer.transports')) {
            $container->getDefinition(MailerCheck::class)->setArgument('$transports', $container->getDefinition('mailer.transports')->getArgument(0));
        }
    }
}
