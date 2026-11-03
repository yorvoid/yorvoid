<?php

declare(strict_types=1);

namespace YorvoID\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use YorvoID\Mail\Mailer;
use YorvoID\Symfony\Doctor\MailerCheck;
use YorvoID\Symfony\Mail\SymfonyMailer;

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

        // An application that sends YorvoID's emails its own way: its mailer, not Symfony's, is checked by its owner.
        if ($container->has(Mailer::class) && SymfonyMailer::class !== $container->findDefinition(Mailer::class)->getClass()) {
            $container->getDefinition(MailerCheck::class)->setArgument('$own', true);
        }
    }
}
