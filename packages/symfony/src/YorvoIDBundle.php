<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Twig\Extension\AbstractExtension;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Flows\Translations as FlowsTranslations;
use YorvoID\IntentSigning\SignedOperationNotification;
use YorvoID\IntentSigning\Translations as IntentSigningTranslations;
use YorvoID\Mail\Mailer;
use YorvoID\Notifications\EmailVerification;
use YorvoID\Notifications\NotificationSettings;
use YorvoID\Notifications\SecurityNotifications;
use YorvoID\Notifications\Translations as NotificationsTranslations;
use YorvoID\Passkeys\Translations as PasskeysTranslations;
use YorvoID\Policy\Attribute\AttributeDeclarations;
use YorvoID\Policy\Attribute\AttributeResolver;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\RoleSource;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Symfony\Controller\FlowController;
use YorvoID\Symfony\Debug\SecretCaster;
use YorvoID\Symfony\Flow\FlowCustomizer;
use YorvoID\Symfony\Flow\FlowSource;
use YorvoID\Symfony\Mail\SymfonyMailer;
use YorvoID\Symfony\Remedy\NotPermittedListener;
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

    /** The pool YorvoID keeps what decisions read in, unless the configuration names another */
    public const string CACHE_POOL = 'yorvoid.cache';

    /**
     * The catalogues of YorvoID's packages, and the bridge's own: its pages' copy.
     */
    public const array TRANSLATIONS = [NotificationsTranslations::DIRECTORY, IntentSigningTranslations::DIRECTORY, PasskeysTranslations::DIRECTORY, FlowsTranslations::DIRECTORY, __DIR__.'/../translations'];

    public function configure(DefinitionConfigurator $definition): void
    {
        // A limit; without a default, one of the preset's.
        $limit = static fn (NodeBuilder $node, string $name, ?string $default, string $info): NodeBuilder => (null === $default ? $node->scalarNode($name) : $node->scalarNode($name)->defaultValue($default))->info($info)
            ->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || 1 !== preg_match(Limits::FORMAT, $value))->thenInvalid('%s is not a limit: write attempts and a period, e.g. "10/15m".')->end()
            ->end();

        $children = $definition->rootNode()->children();
        $children->enumNode('preset')->values(array_keys(Presets::ALL))->defaultValue(Presets::DEFAULT)->info('consumer_site, internal_tool, regulated or agent_platform: the settings you leave out (marked "preset")');
        $children->scalarNode('connection')->defaultValue('default')->info('The DoctrineBundle connection to keep YorvoID\'s tables in');
        $children->scalarNode('realm')->defaultValue('default')->info('The realm people sign in to');
        $children->scalarNode('users')->defaultNull()->info('The service ID of your PrincipalUsers, to sign in your own users; PrincipalUser if not given');
        $children->arrayNode('keys')->addDefaultsIfNotSet()->children()
            ->scalarNode('file')->defaultNull()->info('The signing keys\' file, only its owner may read, e.g. "/run/secrets/yorvoid-keys.json"; yorvoid:keys:rotate creates it')->end()
            ->booleanNode('per_realm')->defaultFalse()->info('Whether each realm has keys of its own, in keys.directory: "acme.json" for the realm "acme"')->end()
            ->scalarNode('directory')->defaultNull()->info('The folder of each realm\'s key file, with per_realm, e.g. "/run/secrets/yorvoid-keys"; yorvoid:keys:rotate --realm creates them')->end()
            ->end()
            ->validate()->ifTrue(static fn (mixed $value): bool => \is_array($value) && true === $value['per_realm'] && null === $value['directory'])
            ->thenInvalid('Keys per realm need a folder for them: set keys.directory.')->end();
        $children->arrayNode('log')->addDefaultsIfNotSet()->children()
            ->scalarNode('checkpoint_file')->defaultNull()->info('Where yorvoid:log:checkpoint appends signed checkpoints of the security log, out of the database user\'s reach, e.g. on another disk or host; needs keys.file')->end()
            ->booleanNode('checkpoint_logger')->defaultFalse()->info('Whether checkpoints also go to the application\'s logger, for a pipeline that ships logs elsewhere')->end();
        $children->arrayNode('decision_log')->addDefaultsIfNotSet()->children()
            ->enumNode('permits')->values(['none', 'sampled', 'all'])->defaultValue('none')->info('Which permits of abilities are recorded: refusals, Indeterminate and every operation\'s decision always are')->end()
            ->floatNode('sample_rate')->min(0.0)->max(1.0)->defaultValue(0.01)->info('With permits: sampled, the share of requests whose permits are recorded')->end()
            ->scalarNode('connection')->defaultNull()->info('A DoctrineBundle connection of its own to the same database, which writes decisions outside the application\'s transactions: give one with audit.on_failure: refuse')->end();
        $children->arrayNode('crypto')->addDefaultsIfNotSet()->children()
            ->enumNode('mode')->values(['standard', 'fips'])->defaultValue('standard')->info('Which algorithms YorvoID uses: standard, or fips, only with OpenSSL\'s FIPS provider alone active, which it checks at boot (CRY-01)')->end();
        $children->arrayNode('audit')->addDefaultsIfNotSet()->children()
            ->enumNode('on_failure')->values(['continue', 'refuse'])->defaultValue('continue')->info('What a decision whose record can\'t be written does: continue, or refuse, so that nothing is permitted without its record (AUD-006). The alert is raised either way')->end();
        $children->arrayNode('cache')->addDefaultsIfNotSet()->children()
            ->scalarNode('pool')->defaultValue(self::CACHE_POOL)->info('The PSR-6 pool that servers share for what decisions read: roles, assignments, relations. yorvoid.cache is on cache.app; null keeps them for the request only')->end();
        $children->arrayNode('cookies')->addDefaultsIfNotSet()->children()
            ->scalarNode('device')->defaultValue('__Host-yorvoid_device')->info('The cookie that recognises devices')->end();
        $children->arrayNode('storage')->addDefaultsIfNotSet()->children()
            ->scalarNode('prefix')->defaultValue(StorageLocation::DEFAULT_PREFIX)->info('Prefix of YorvoID\'s tables')->end()
            ->scalarNode('schema')->defaultNull()->info('A PostgreSQL schema of their own')->end();
        $children->arrayNode('sessions')->addDefaultsIfNotSet()->children()
            ->enumNode('preset')->values(array_keys(Factories::SESSION_PRESETS))->info('aal2: 24h, 1h idle; aal3: 12h, 15m idle; console: 8h, 1h idle; preset')->end()
            ->scalarNode('absolute')->defaultNull()->info('Overrides the preset, e.g. "8h"')->end()
            ->scalarNode('idle')->defaultNull()->info('Overrides the preset, e.g. "30m"')->end()
            ->booleanNode('recognise_devices')->info('Recognise devices by a cookie, and announce new ones; preset')->end();
        $children->arrayNode('passkeys')->isRequired()->children()
            ->scalarNode('relying_party_id')->isRequired()->cannotBeEmpty()->info('E.g. "example.com"')->end()
            ->arrayNode('origins')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->info('E.g. ["https://example.com"]')->end()
            ->enumNode('user_verification')->values(['required', 'preferred'])->info('Preset')->end()
            ->enumNode('clone_policy')->values(['refuse_sign_in', 'disable_passkey', 'allow'])->defaultValue('refuse_sign_in')->end()
            ->scalarNode('timeout')->defaultValue('5m')->end();
        $children->arrayNode('step_up')->addDefaultsIfNotSet()->children()
            ->scalarNode('window')->info('How recent a proof changing sign-in methods needs; preset')->end()
            ->scalarNode('cool_down')->info('How long sensitive operations wait after an account recovery, e.g. "24h", or ~ for none; preset')->end();
        $children->arrayNode('recovery_codes')->addDefaultsIfNotSet()->children()
            ->integerNode('count')->defaultValue(10)->min(1)->max(100)->end();
        $limits = $children->arrayNode('limits')->addDefaultsIfNotSet()->children();
        $limit($limits, 'per_account_and_address', null, 'Attempts at a secret, one account from one address; preset');
        $limit($limits, 'per_account', null, 'Attempts at a secret, one account from anywhere; preset');
        $limit($limits, 'per_address', null, 'Attempts at a secret, one address; preset');
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
            ->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || !LocalPath::is($value))->thenInvalid('%s is not a path on this site: start it with one "/", and use no "\\" or spaces.')->end()
            ->end();
        $flows = $children->arrayNode('flows')->addDefaultsIfNotSet()->children();
        $path($flows, 'completed_path', '/', 'Where people go when a flow completes, e.g. after signing in');
        $flows->arrayNode('completed_paths')->useAttributeAsKey('flow')->info('Where to go after particular flows, e.g. {yorvoid.sign_in: /dashboard}')
            ->scalarPrototype()->validate()->ifTrue(static fn (mixed $value): bool => !\is_string($value) || !LocalPath::is($value))->thenInvalid('%s is not a path on this site: start it with one "/", and use no "\\" or spaces.')->end()->end()->end();
        $flows->scalarNode('script')->defaultValue('/bundles/yorvoid/browser/forms.js')->info('Where the pages load the browser client\'s form script from')->end();
        $children->arrayNode('remedies')->addDefaultsIfNotSet()->children()
            ->booleanNode('pages')->defaultTrue()->info('Whether an operation refused and not caught is answered with a page of what would satisfy it (403), or that it couldn\'t be checked (503); off, Symfony\'s error page shows')->end();
        $children->arrayNode('intents')->addDefaultsIfNotSet()->children()
            ->scalarNode('summaries_domain')->defaultValue('messages')->info('The translation domain of your operations\' summaries')->end();
        $children->arrayNode('ordered_conditions')->addDefaultsIfNotSet()->children()
            ->booleanNode('pessimistic')->defaultFalse()->info('Whether rules decide an unknown ordered fact at its worst instead of as missing data (experimental)')->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Only with Symfony's translation component: the paths would turn the translator on, and an
        // application without it fails to start. YorvoID then uses its own translator.
        if ($builder->hasExtension('framework') && class_exists(Translator::class)) {
            $builder->prependExtensionConfig('framework', ['translator' => ['paths' => self::TRANSLATIONS]]);
        }

        // A pool of its own, not cache.app: what's in it decides who holds which role.
        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', ['cache' => ['pools' => [self::CACHE_POOL => ['adapter' => 'cache.app']]]]);
        }
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // What the configuration leaves out, its preset gives: settings without a default are absent.
        $preset = $config['preset'];
        \assert(\is_string($preset));
        $settings = $this->flatten($config) + Presets::ALL[$preset];

        foreach ($settings as $name => $value) {
            $container->parameters()->set('yorvoid.'.$name, $value);
        }

        BootCheck::check($settings, static fn (mixed $value): bool => \is_string($value) && $builder->resolveEnvPlaceholders($value, '%%env(%s)%%') !== $value);

        $container->import(\dirname(__DIR__).'/config/services.php');
        $connection = $settings['connection'];
        \assert(\is_string($connection));
        $builder->setAlias('yorvoid.connection', \sprintf('doctrine.dbal.%s_connection', $connection));
        // DoctrineBundle adds a copy of it to that connection, as "<id>.<connection>": the one that sees the commits.
        $builder->getDefinition('yorvoid.transaction_middleware')->addTag('doctrine.middleware', ['connection' => $connection]);
        $builder->getDefinition(DbalTransactions::class)->setArgument(1, new Reference('yorvoid.transaction_middleware.'.$connection));
        $decisions = $settings['decision_log.connection'];

        if (null !== $decisions) {
            \assert(\is_string($decisions));
            $builder->getDefinition(DbalDecisionLogStore::class)->setArgument(1, new Reference(\sprintf('doctrine.dbal.%s_connection', $decisions)));
        }

        $completed = $settings['flows.completed_paths'];
        \assert(\is_array($completed));
        $builder->getDefinition(FlowController::class)->setArgument('$completed', $completed + ['default' => $settings['flows.completed_path']]);
        $builder->registerForAutoconfiguration(FlowCustomizer::class)->addTag('yorvoid.flow_customizer');
        // The application's own operations run through Operations too.
        $builder->registerForAutoconfiguration(OperationHandler::class)->addTag('yorvoid.operation_handler');
        $builder->registerForAutoconfiguration(PolicyElement::class)->addTag('yorvoid.policy_element');
        $builder->registerForAutoconfiguration(PolicyDeclarations::class)->addTag('yorvoid.policy_declarations');
        $builder->registerForAutoconfiguration(Rule::class)->addTag('yorvoid.rule');
        $builder->registerForAutoconfiguration(RoleSource::class)->addTag('yorvoid.role_source');
        $builder->registerForAutoconfiguration(RelationDeclarations::class)->addTag('yorvoid.relation_declarations');
        $builder->registerForAutoconfiguration(AttributeDeclarations::class)->addTag('yorvoid.attribute_declarations');
        $builder->registerForAutoconfiguration(AttributeResolver::class)->addTag('yorvoid.attribute_resolver');
        $builder->registerForAutoconfiguration(FlowSource::class)->addTag('yorvoid.flow_source');

        // The flow pages' translations, when the application renders them with Twig.
        if (class_exists(AbstractExtension::class)) {
            $builder->register(YorvoIDExtension::class)->setAutowired(true)->setArgument('$translator', new Reference('yorvoid.translator.used'))->addTag('twig.extension');
        }

        if (true !== $settings['remedies.pages']) {
            $builder->removeDefinition(NotPermittedListener::class);
        } else {
            $builder->getDefinition(NotPermittedListener::class)->setArgument('$completedPath', $settings['flows.completed_path']);
        }

        $users = $settings['users'];

        if (\is_string($users)) {
            $builder->setAlias(PrincipalUsers::class, $users);
        }

        // The default pool needs FrameworkBundle; one the application names must exist.
        $pool = $settings['cache.pool'];
        $builder->getDefinition(VersionedCache::class)->setArgument(0, \is_string($pool) ? new Reference($pool, self::CACHE_POOL === $pool ? ContainerInterface::NULL_ON_INVALID_REFERENCE : ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE) : null);

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

        // A new address, or the claiming owner's, gets a link to confirm it: notifications go only there.
        $builder->register(EmailVerification::class)->setAutowired(true)->setPublic(true)
            ->setArgument('$translator', new Reference('yorvoid.translator.used'))
            ->setArgument('$link', (new Definition(\Closure::class))->setFactory([Factories::class, 'emailVerificationLink'])->setArguments([new Reference(UrlGeneratorInterface::class)]))
            ->addTag('kernel.event_listener', ['method' => 'identifierChanged'])
            ->addTag('kernel.event_listener', ['method' => 'principalCreated'])
            ->addTag('yorvoid.operation_handler');
    }

    /**
     * Symfony's dumper (dump(), the profiler, the debug toolbar) reads an object's properties, not its
     * __debugInfo(): it shows a Secret redacted only with this caster (PLT-053).
     */
    public function boot(): void
    {
        if (class_exists(AbstractCloner::class)) {
            AbstractCloner::$defaultCasters[Secret::class] = [SecretCaster::class, 'cast'];
        }

        // The FIPS mode refuses to start unless OpenSSL runs with its FIPS provider alone (CRY-01).
        if ('fips' === $this->container?->getParameter('yorvoid.crypto.mode')) {
            $this->container->get(CryptoPolicy::class);
        }
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
