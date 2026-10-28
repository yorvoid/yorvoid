<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\Event\CredentialRemoving;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Invitation\Invitations;
use YorvoID\Authn\Notification\NotMe;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Authn\Session\EndSessionsOfInactivePrincipals;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Session\Storage\DbalKnownDeviceStore;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\Setup\Storage\DbalSetupStore;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Flows\Flows;
use YorvoID\Flows\FlowStore;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\Passkeys\Intent\IntentSummaries;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Passkeys\RemovePasskeyWithCredential;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;
use YorvoID\Symfony\Command\MigrateCommand;
use YorvoID\Symfony\Command\PurgeCommand;
use YorvoID\Symfony\Command\RecoveryLinkCommand;
use YorvoID\Symfony\Command\SetupCodeCommand;
use YorvoID\Symfony\Command\TemplatesDiffCommand;
use YorvoID\Symfony\Command\TemplatesLintCommand;
use YorvoID\Symfony\Command\TemplatesPublishCommand;
use YorvoID\Symfony\Controller\CeremonyController;
use YorvoID\Symfony\Controller\FlowController;
use YorvoID\Symfony\Controller\TemplateGalleryController;
use YorvoID\Symfony\Factories;
use YorvoID\Symfony\Flow\FlowContexts;
use YorvoID\Symfony\Flow\SessionFlowStore;
use YorvoID\Symfony\Limits;
use YorvoID\Symfony\Security\Authenticator\PasskeyAuthenticator;
use YorvoID\Symfony\Security\Authenticator\RecoveryCodeAuthenticator;
use YorvoID\Symfony\Security\Authenticator\RecoveryLinkAuthenticator;
use YorvoID\Symfony\Security\Authenticator\SessionAuthenticator;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\DefaultPrincipalUsers;
use YorvoID\Symfony\Security\PrincipalUserChecker;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Symfony\Security\PrincipalUsers;
use YorvoID\Symfony\Security\ValidateSessionListener;
use YorvoID\Symfony\Templates\FlowPages;
use YorvoID\Symfony\Templates\TemplateLint;
use YorvoID\Symfony\Templates\TemplatePublisher;
use YorvoID\Symfony\YorvoIDBundle;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\ThrottleStore;
use YorvoID\Translation\Translator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * YorvoID's services. Its own classes are autowired among themselves; what comes from the
 * application (the connection, the clock, the dispatcher, the translator, the mailer) is wired by
 * YorvoIDBundle and YorvoIDPass.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->private();

    // Storage: the application's DBAL connection (set by YorvoIDBundle), YorvoID's tables.
    $services->set('yorvoid.transaction_middleware', TransactionMiddleware::class);
    $services->set(StorageLocation::class)->args([param('yorvoid.storage.prefix'), param('yorvoid.storage.schema')]);
    $services->set(Database::class)->args([service('yorvoid.connection'), service(StorageLocation::class)]);
    // The middleware's instance on that connection, set by YorvoIDBundle.
    $services->set(DbalTransactions::class)->args([service('yorvoid.connection'), null]);
    $services->alias(Transactions::class, DbalTransactions::class)->public();

    foreach ([
        PrincipalStore::class => DbalPrincipalStore::class,
        IdentifierStore::class => DbalIdentifierStore::class,
        SecurityLogStore::class => DbalSecurityLogStore::class,
        SessionStore::class => DbalSessionStore::class,
        KnownDeviceStore::class => DbalKnownDeviceStore::class,
        OneTimeTokenStore::class => DbalOneTimeTokenStore::class,
        SetupStore::class => DbalSetupStore::class,
        CredentialStore::class => DbalCredentialStore::class,
        RecoveryCodeStore::class => DbalRecoveryCodeStore::class,
        CoolDownStore::class => DbalCoolDownStore::class,
        PasskeyStore::class => DbalPasskeyStore::class,
        CeremonyStore::class => DbalCeremonyStore::class,
        ThrottleStore::class => DbalThrottleStore::class,
    ] as $interface => $implementation) {
        $services->set($implementation);
        $services->alias($interface, $implementation);
    }

    $services->set(UuidV7Generator::class)->args([service(ClockInterface::class)]);
    $services->alias(IdGenerator::class, UuidV7Generator::class);

    // YorvoID's own translator, for applications without Symfony's (see YorvoIDPass).
    $services->set('yorvoid.translator', Translator::class)->args([YorvoIDBundle::TRANSLATIONS, '%kernel.default_locale%']);

    // Policies from the configuration.
    $services->set(SessionPolicy::class)->factory([Factories::class, 'sessionPolicy'])->args([param('yorvoid.sessions.preset'), param('yorvoid.sessions.absolute'), param('yorvoid.sessions.idle')]);
    $services->set(PasskeyPolicy::class)->factory([Factories::class, 'passkeyPolicy'])->args([param('yorvoid.passkeys.relying_party_id'), param('yorvoid.passkeys.origins'), param('yorvoid.passkeys.user_verification'), param('yorvoid.passkeys.clone_policy'), param('yorvoid.passkeys.timeout')]);
    $services->set(AttemptLimits::class)->factory([Factories::class, 'attemptLimits'])->args([param('yorvoid.limits.per_account_and_address'), param('yorvoid.limits.per_account'), param('yorvoid.limits.per_address')]);

    // The services.
    $services->set(SecurityLog::class);
    $services->set(Principals::class)->arg('$guardRules', tagged_iterator('yorvoid.guard_rule'));
    $services->set(Sessions::class)->arg('$devices', service(KnownDeviceStore::class)->nullOnInvalid());
    $services->set(StepUp::class)->arg('$coolDown', inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.cool_down')]));
    $services->set(Credentials::class)->arg('$reauthenticationWindow', inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]));
    $services->set(OneTimeTokens::class);
    $services->set(Throttle::class);
    $services->set(Attempts::class);
    $services->set(SetupCodes::class);
    $services->set(Invitations::class)->arg('$emailLimit', inline_service()->factory([Limits::class, 'parse'])->args([param('yorvoid.limits.invitation_emails')]));
    $services->set(RecoveryLinks::class);
    $services->set(RecoveryCodes::class)->arg('$count', param('yorvoid.recovery_codes.count'));
    $services->set(NotMe::class);
    $services->set(Passkeys::class);
    $services->set(IntentSummaries::class)->args([service('yorvoid.translator.used'), param('yorvoid.intents.summaries_domain')]);
    $services->set(IntentSigning::class)->arg('$limit', inline_service()->factory([Limits::class, 'parse'])->args([param('yorvoid.limits.signature_requests')]));

    // Listeners of YorvoID's events, through Symfony's dispatcher.
    $services->set(EndSessionsOfInactivePrincipals::class)->tag('kernel.event_listener', ['event' => PrincipalStatusChanging::class]);
    $services->set(RemovePasskeyWithCredential::class)->tag('kernel.event_listener', ['event' => CredentialRemoving::class]);

    // Security: the user, the request's YorvoID session, the authenticators, the endpoints.
    $services->set(Realm::class)->args([param('yorvoid.realm')]);
    $services->set(DefaultPrincipalUsers::class);
    $services->alias(PrincipalUsers::class, DefaultPrincipalUsers::class);
    $services->set(PrincipalUserProvider::class)->public();
    $services->set(PrincipalUserChecker::class)->public();
    $services->set(CurrentSession::class)->public()->tag('kernel.reset', ['method' => 'reset']);
    // Before the firewall (8), which would sign in from the PHP session.
    $services->set(ValidateSessionListener::class)->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 9]);

    foreach ([PasskeyAuthenticator::class, RecoveryCodeAuthenticator::class, RecoveryLinkAuthenticator::class] as $authenticator) {
        $services->set($authenticator)->arg('$deviceCookie', param('yorvoid.cookies.device'))->public();
    }

    $services->set(CeremonyController::class)->public()->tag('controller.service_arguments')
        ->arg('$window', inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]));

    // Flows: YorvoID's journeys as the application changed them, kept in the PHP session.
    $window = inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]);
    $services->set(SessionFlowStore::class);
    $services->alias(FlowStore::class, SessionFlowStore::class);
    $services->set(PasskeyFlows::class)->arg('$window', $window);
    $services->set(Flows::class)->arg('$flows', inline_service()->factory([Factories::class, 'flows'])->args([service(PasskeyFlows::class), tagged_iterator('yorvoid.flow_customizer')]))->public();
    $services->set(FlowContexts::class)->arg('$deviceCookie', param('yorvoid.cookies.device'))->public();
    $services->set(SessionAuthenticator::class)->public();
    $services->set(FlowPages::class)
        ->arg('$translator', service('yorvoid.translator.used'))
        ->arg('$twig', service('twig')->nullOnInvalid())
        ->arg('$script', param('yorvoid.flows.script'));
    $services->set(FlowController::class)->public()->tag('controller.service_arguments')
        ->arg('$completed', [])
        ->arg('$deviceCookie', param('yorvoid.cookies.device'));

    // The templates: the gallery, the lint, publishing them and what changed since.
    $services->set(TemplateLint::class)->public();
    $services->set(TemplatePublisher::class)->arg('$projectDir', param('kernel.project_dir'))->arg('$source', null);
    $services->set(TemplateGalleryController::class)->public()->tag('controller.service_arguments')->arg('$debug', param('kernel.debug'));

    // Console commands.
    foreach ([MigrateCommand::class, PurgeCommand::class, SetupCodeCommand::class, RecoveryLinkCommand::class, TemplatesPublishCommand::class, TemplatesDiffCommand::class, TemplatesLintCommand::class] as $command) {
        $services->set($command)->tag('console.command');
    }

    // The application uses these.
    foreach ([Principals::class, Sessions::class, StepUp::class, Credentials::class, OneTimeTokens::class, Throttle::class, Attempts::class, SetupCodes::class, Invitations::class, RecoveryLinks::class, RecoveryCodes::class, NotMe::class, Passkeys::class, IntentSummaries::class, IntentSigning::class, SecurityLog::class] as $public) {
        $services->get($public)->public();
    }
};
