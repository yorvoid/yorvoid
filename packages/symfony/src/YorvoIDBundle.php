<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Twig\Extension\AbstractExtension;
use YorvoID\Authn\Notification\NotificationSettings;
use YorvoID\Authn\Notification\SecurityNotifications;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Translations as AuthnTranslations;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Flows\Translations as FlowsTranslations;
use YorvoID\Mail\Mailer;
use YorvoID\Passkeys\Intent\SignedOperationNotification;
use YorvoID\Passkeys\Translations as PasskeysTranslations;
use YorvoID\Symfony\Controller\FlowController;
use YorvoID\Symfony\Flow\FlowCustomizer;
use YorvoID\Symfony\Mail\SymfonyMailer;
use YorvoID\Symfony\Security\PrincipalUsers;
use YorvoID\Symfony\Twig\YorvoIDExtension;

/**
 * YorvoID in a Symfony application (PLT-010…014): its services from the configuration under
 * "yorvoid:", its events through Symfony's dispatcher, its emails through Symfony Mailer, its messages
 * through Symfony's translator, and its console commands. It never defines a firewall: the
 * authenticators go into the application's own.
 *
 * @api
 */
final class YorvoIDBundle extends AbstractBundle
{
    protected string $extensionAlias = 'yorvoid';

    /**
     * The catalogues of YorvoID's packages, and the bridge's own: its pages' copy.
     */
    public const array TRANSLATIONS = [AuthnTranslations::DIRECTORY, PasskeysTranslations::DIRECTORY, FlowsTranslations::DIRECTORY, __DIR__.'/../translations'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $limit = static fn (NodeBuilder $node, string $name, string $default, string $info): NodeBuilder => $node->scalarNode($name)->defaultValue($default)->info($info)
            ->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || 1 !== preg_match(Limits::FORMAT, $value))->thenInvalid('%s is not a limit: write attempts and a period, e.g. "10/15m".')->end()
            ->end();

        $children = $definition->rootNode()->children();
        $children->scalarNode('connection')->defaultValue('default')->info('The DoctrineBundle connection to keep YorvoID\'s tables in');
        $children->scalarNode('realm')->defaultValue('default')->info('The realm people sign in to');
        $children->scalarNode('users')->defaultNull()->info('The service ID of your PrincipalUsers, to sign in your own users; PrincipalUser if not given');
        $children->arrayNode('cookies')->addDefaultsIfNotSet()->children()
            ->scalarNode('device')->defaultValue('__Host-yorvoid_device')->info('The cookie that recognises devices')->end();
        $children->arrayNode('storage')->addDefaultsIfNotSet()->children()
            ->scalarNode('prefix')->defaultValue(StorageLocation::DEFAULT_PREFIX)->info('Prefix of YorvoID\'s tables')->end()
            ->scalarNode('schema')->defaultNull()->info('A PostgreSQL schema of their own')->end();
        $children->arrayNode('sessions')->addDefaultsIfNotSet()->children()
            ->enumNode('preset')->values(array_keys(Factories::SESSION_PRESETS))->defaultValue('aal2')->info('aal2: 24h, 1h idle; aal3: 12h, 15m idle; console: 8h, 1h idle')->end()
            ->scalarNode('absolute')->defaultNull()->info('Overrides the preset, e.g. "8h"')->end()
            ->scalarNode('idle')->defaultNull()->info('Overrides the preset, e.g. "30m"')->end()
            ->booleanNode('recognise_devices')->defaultTrue()->info('Recognise devices by a cookie, and announce new ones')->end();
        $children->arrayNode('passkeys')->isRequired()->children()
            ->scalarNode('relying_party_id')->isRequired()->cannotBeEmpty()->info('E.g. "example.com"')->end()
            ->arrayNode('origins')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->info('E.g. ["https://example.com"]')->end()
            ->enumNode('user_verification')->values(['required', 'preferred'])->defaultValue('required')->end()
            ->enumNode('clone_policy')->values(['refuse_sign_in', 'disable_passkey', 'allow'])->defaultValue('refuse_sign_in')->end()
            ->scalarNode('timeout')->defaultValue('5m')->end();
        $children->arrayNode('step_up')->addDefaultsIfNotSet()->children()
            ->scalarNode('window')->defaultValue('5m')->info('How recent a proof changing sign-in methods needs')->end()
            ->scalarNode('cool_down')->defaultNull()->info('How long sensitive operations wait after an account recovery, e.g. "24h"; none if not given')->end();
        $children->arrayNode('recovery_codes')->addDefaultsIfNotSet()->children()
            ->integerNode('count')->defaultValue(10)->min(1)->max(100)->end();
        $limits = $children->arrayNode('limits')->addDefaultsIfNotSet()->children();
        $limit($limits, 'per_account_and_address', '10/15m', 'Attempts at a secret, one account from one address');
        $limit($limits, 'per_account', '100/1h', 'Attempts at a secret, one account from anywhere');
        $limit($limits, 'per_address', '300/15m', 'Attempts at a secret, one address');
        $limit($limits, 'invitation_emails', '1/2m', 'Invitations sent again to one person');
        $limit($limits, 'signature_requests', '20/1h', 'Signatures of intents asked of one person');
        $children->arrayNode('notifications')->canBeDisabled()->children()
            ->scalarNode('application_name')->defaultNull()->info('What people call the application, e.g. "Acme"')->end()
            ->scalarNode('not_me_url')->defaultNull()->info('The "This wasn\'t me" page, with {token} and {credential}')->end()
            ->scalarNode('from')->defaultNull()->info('The address emails come from, e.g. "security@example.com"')->end()
            ->scalarNode('locales')->defaultNull()->info('The service ID of a PrincipalLocales, for each principal\'s language; English if not given')->end()
            ->end()
            ->validate()->ifTrue(static fn (mixed $value): bool => \is_array($value) && true === $value['enabled'] && (null === $value['application_name'] || null === $value['not_me_url'] || null === $value['from']))
            ->thenInvalid('Notifications need application_name, not_me_url and from; or turn them off with enabled: false.')->end();
        $path = static fn (NodeBuilder $node, string $name, string $default, string $info): NodeBuilder => $node->scalarNode($name)->defaultValue($default)->info($info)
            ->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || 1 !== preg_match('#^/(?!/)#', $value))->thenInvalid('%s is not a path on this site: start it with one "/".')->end()
            ->end();
        $flows = $children->arrayNode('flows')->addDefaultsIfNotSet()->children();
        $path($flows, 'completed_path', '/', 'Where people go when a flow completes, e.g. after signing in');
        $flows->arrayNode('completed_paths')->useAttributeAsKey('flow')->info('Where to go after particular flows, e.g. {yorvoid.sign_intent: /console/confirmed}')
            ->scalarPrototype()->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || 1 !== preg_match('#^/(?!/)#', $value))->thenInvalid('%s is not a path on this site: start it with one "/".')->end()->end()->end();
        $flows->scalarNode('script')->defaultValue('/bundles/yorvoid/browser/forms.js')->info('Where the pages load the browser client\'s form script from')->end();
        $children->arrayNode('intents')->addDefaultsIfNotSet()->children()
            ->scalarNode('summaries_domain')->defaultValue('messages')->info('The translation domain of your operations\' summaries')->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', ['translator' => ['paths' => self::TRANSLATIONS]]);
        }
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $settings = $this->flatten($config);

        foreach ($settings as $name => $value) {
            $container->parameters()->set('yorvoid.'.$name, $value);
        }

        $container->import(\dirname(__DIR__).'/config/services.php');
        $connection = $settings['connection'];
        \assert(\is_string($connection));
        $builder->setAlias('yorvoid.connection', \sprintf('doctrine.dbal.%s_connection', $connection));
        // DoctrineBundle adds a copy of it to that connection, as "<id>.<connection>": the one that sees the commits.
        $builder->getDefinition('yorvoid.transaction_middleware')->addTag('doctrine.middleware', ['connection' => $connection]);
        $builder->getDefinition(DbalTransactions::class)->setArgument(1, new Reference('yorvoid.transaction_middleware.'.$connection));

        $completed = $settings['flows.completed_paths'];
        \assert(\is_array($completed));
        $builder->getDefinition(FlowController::class)->setArgument('$completed', $completed + ['default' => $settings['flows.completed_path']]);
        $builder->registerForAutoconfiguration(FlowCustomizer::class)->addTag('yorvoid.flow_customizer');

        // The flow pages' translations, when the application renders them with Twig.
        if (class_exists(AbstractExtension::class)) {
            $builder->register(YorvoIDExtension::class)->setAutowired(true)->setArgument('$translator', new Reference('yorvoid.translator.used'))->addTag('twig.extension');
        }

        $users = $settings['users'];

        if (\is_string($users)) {
            $builder->setAlias(PrincipalUsers::class, $users);
        }

        if (true !== $settings['sessions.recognise_devices']) {
            $builder->getDefinition(Sessions::class)->setArgument('$devices', null);
        }

        if (true === $settings['notifications.enabled']) {
            $this->notifications($builder, $settings);
        }
    }

    /**
     * Security notifications: emails through Symfony Mailer, listeners of the events they're about.
     *
     * @param array<string, mixed> $settings
     */
    private function notifications(ContainerBuilder $builder, array $settings): void
    {
        $locales = $settings['notifications.locales'];
        $builder->register(Mailer::class, SymfonyMailer::class)->setArguments([new Reference(MailerInterface::class), new Definition(Address::class, [$settings['notifications.from']])]);
        $builder->register(NotificationSettings::class)->setFactory([Factories::class, 'notificationSettings'])->setArguments([
            $settings['notifications.application_name'],
            $settings['notifications.not_me_url'],
            \is_string($locales) ? new Reference($locales) : null,
        ]);
        $builder->register(SecurityNotifications::class)->setAutowired(true)->setArgument('$translator', new Reference('yorvoid.translator.used'));

        // Each method's parameter says which event it's for.
        foreach (SecurityNotifications::events() as $method) {
            $builder->getDefinition(SecurityNotifications::class)->addTag('kernel.event_listener', ['method' => $method]);
        }

        $builder->register(SignedOperationNotification::class)->setAutowired(true)->addTag('kernel.event_listener');
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DependencyInjection\YorvoIDPass());
    }

    /**
     * The configuration as dotted parameter names: "passkeys.origins" and the like.
     *
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    private function flatten(array $config): array
    {
        $flat = [];

        foreach ($config as $section => $value) {
            if (!\is_array($value)) {
                $flat[(string) $section] = $value;

                continue;
            }

            foreach ($value as $name => $setting) {
                $flat[$section.'.'.$name] = $setting;
            }
        }

        return $flat;
    }
}
