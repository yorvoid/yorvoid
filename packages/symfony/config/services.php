<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\Event\CredentialRemoving;
use YorvoID\Authn\Credential\ForgetCredentialsOfDeletedPrincipals;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Invitation\Invitations;
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
use YorvoID\Authn\StepUp\SessionStepUpCheck;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpSelfService;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
use YorvoID\Core\Authorization\DecisionLog\AuditFailure;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\OfflineDecider;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Principal\Storage\IdentifierRenormalization;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Flows\Flows;
use YorvoID\Flows\FlowStore;
use YorvoID\IntentSigning\IntentSigning;
use YorvoID\IntentSigning\IntentSummaries;
use YorvoID\Notifications\EmailVerification;
use YorvoID\Notifications\NotMe;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Passkeys\RemovePasskeyWithCredential;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;
use YorvoID\Policy\Attribute\AttributeChanges;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Attribute\AttributeStore;
use YorvoID\Policy\Attribute\ForgetAttributesOfDeletedPrincipals;
use YorvoID\Policy\ForgetRolesOfDeletedPrincipals;
use YorvoID\Policy\GroupChanges;
use YorvoID\Policy\GroupRelations;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\Ordered\OrderedConditions;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyChanges;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\PolicyVersion;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Roles;
use YorvoID\Policy\Storage\CachedPolicyStore;
use YorvoID\Policy\Storage\DbalAttributeStore;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Policy\ViewAs\Views;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\ForgetRelationsOfDeletedPrincipals;
use YorvoID\Relations\RelationChanges;
use YorvoID\Relations\RelationPermit;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationsVersion;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Relations\Storage\DbalRelationStore;
use YorvoID\Symfony\Command\DoctorCommand;
use YorvoID\Symfony\Command\IdentifiersNormalizeCommand;
use YorvoID\Symfony\Command\KeysRotateCommand;
use YorvoID\Symfony\Command\LogAnchorCommand;
use YorvoID\Symfony\Command\LogCheckpointCommand;
use YorvoID\Symfony\Command\LogVerifyCommand;
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
use YorvoID\Symfony\Doctor\AssuranceCheck;
use YorvoID\Symfony\Doctor\AuditCheck;
use YorvoID\Symfony\Doctor\ClockCheck;
use YorvoID\Symfony\Doctor\ConfigurationCheck;
use YorvoID\Symfony\Doctor\CookiesCheck;
use YorvoID\Symfony\Doctor\CryptoCheck;
use YorvoID\Symfony\Doctor\EnvironmentCheck;
use YorvoID\Symfony\Doctor\KeyAlgorithmsCheck;
use YorvoID\Symfony\Doctor\KeysCheck;
use YorvoID\Symfony\Doctor\LogCheckpointsCheck;
use YorvoID\Symfony\Doctor\LogGrantsCheck;
use YorvoID\Symfony\Doctor\MailerCheck;
use YorvoID\Symfony\Doctor\MigrationsCheck;
use YorvoID\Symfony\Doctor\PresetCheck;
use YorvoID\Symfony\Doctor\ProxiesCheck;
use YorvoID\Symfony\Doctor\TemplatesCheck;
use YorvoID\Symfony\Doctor\TestHelpersCheck;
use YorvoID\Symfony\Factories;
use YorvoID\Symfony\Flow\FlowContexts;
use YorvoID\Symfony\Flow\SessionFlowStore;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;
use YorvoID\Symfony\Key\KeyFiles;
use YorvoID\Symfony\Limits;
use YorvoID\Symfony\Remedy\NotPermittedListener;
use YorvoID\Symfony\Remedy\RemedyPrompts;
use YorvoID\Symfony\Security\Authenticator\PasskeyAuthenticator;
use YorvoID\Symfony\Security\Authenticator\RecoveryCodeAuthenticator;
use YorvoID\Symfony\Security\Authenticator\RecoveryLinkAuthenticator;
use YorvoID\Symfony\Security\Authenticator\SessionAuthenticator;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\DefaultPrincipalUsers;
use YorvoID\Symfony\Security\EndSessionOnSignOut;
use YorvoID\Symfony\Security\PolicyVoter;
use YorvoID\Symfony\Security\PrincipalUserChecker;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Symfony\Security\PrincipalUsers;
use YorvoID\Symfony\Security\SignInEntryPoint;
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
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

/*
 * YorvoID's services. Its own classes are autowired among themselves; what comes from the
 * application (the connection, the clock, the dispatcher, the translator, the mailer) is wired by
 * YorvoIDBundle and YorvoIDPass.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->private();
    // Every service that runs operations is one of Operations' handlers.
    $services->instanceof(OperationHandler::class)->tag('yorvoid.operation_handler');

    // Storage: the application's DBAL connection (set by YorvoIDBundle), YorvoID's tables.
    $services->set('yorvoid.transaction_middleware', TransactionMiddleware::class);
    $services->set(StorageLocation::class)->args([param('yorvoid.storage.prefix'), param('yorvoid.storage.schema')]);
    $services->set(Database::class)->args([service('yorvoid.connection'), service(StorageLocation::class)]);
    // The middleware's instance on that connection, set by YorvoIDBundle.
    $services->set(DbalTransactions::class)->args([service('yorvoid.connection'), null]);
    $services->alias(Transactions::class, DbalTransactions::class)->public();
    $services->set(IdentifierRenormalization::class);

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
        PolicyStore::class => DbalPolicyStore::class,
        RelationStore::class => DbalRelationStore::class,
        AttributeStore::class => DbalAttributeStore::class,
        DecisionLogStore::class => DbalDecisionLogStore::class,
    ] as $interface => $implementation) {
        $services->set($implementation);
        $services->alias($interface, $implementation);
    }

    // What decisions read, kept against the realm's versions: once a request, and in the shared pool
    // the configuration names (cache.pool, set by the bundle).
    // On the application's connection unless yorvoid.decision_log.connection names one of its own (YorvoIDBundle).
    $services->get(DbalDecisionLogStore::class)->arg('$writes', null);
    $services->set(Installation::class)->factory([Installation::class, 'stored'])->args([service(Database::class)]);
    $services->set(VersionedCache::class)->args([null, service(Installation::class), service('logger')->nullOnInvalid()])->tag('kernel.reset', ['method' => 'reset']);
    $services->set(CachedPolicyStore::class)->args([service(DbalPolicyStore::class), service(VersionedCache::class)])->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(PolicyStore::class, CachedPolicyStore::class);
    $services->set(CachedRelationStore::class)->args([service(DbalRelationStore::class), service(VersionedCache::class)])->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(RelationStore::class, CachedRelationStore::class);

    $services->set(UuidV7Generator::class)->args([service(ClockInterface::class)]);
    $services->alias(IdGenerator::class, UuidV7Generator::class);

    // YorvoID's own translator, for applications without Symfony's (see YorvoIDPass).
    $services->set('yorvoid.translator', Translator::class)->args([YorvoIDBundle::TRANSLATIONS, '%kernel.default_locale%']);

    // Policies from the configuration.
    $services->set(SessionPolicy::class)->factory([Factories::class, 'sessionPolicy'])->args([param('yorvoid.sessions.preset'), param('yorvoid.sessions.absolute'), param('yorvoid.sessions.idle')]);
    $services->set(PasskeyPolicy::class)->factory([Factories::class, 'passkeyPolicy'])->args([param('yorvoid.passkeys.relying_party_id'), param('yorvoid.passkeys.origins'), param('yorvoid.passkeys.user_verification'), param('yorvoid.passkeys.clone_policy'), param('yorvoid.passkeys.timeout'), service(CryptoPolicy::class)]);
    // Probed when it's built: in the FIPS mode, at boot (YorvoIDBundle::boot()).
    $services->set(CryptoPolicy::class)->factory([Factories::class, 'cryptoPolicy'])->args([param('yorvoid.crypto.mode'), service(KeyFiles::class)])->public();
    $services->set(AttemptLimits::class)->factory([Factories::class, 'attemptLimits'])->args([param('yorvoid.limits.per_account_and_address'), param('yorvoid.limits.per_account'), param('yorvoid.limits.per_address')]);

    // The signing keys: one file for every realm, or one for each (D-40).
    $services->set(KeyFiles::class)->args([param('yorvoid.keys.file'), param('yorvoid.keys.per_realm'), param('yorvoid.keys.directory')]);

    // The services.
    $services->set(SecurityLog::class)->tag('kernel.reset', ['method' => 'reset']);
    // Ability decisions wait for the end of what asked for them: a request, a command, a message.
    $services->set(DecisionLog::class)
        ->args([
            service(DecisionLogStore::class),
            inline_service(PermitRecording::class)->factory([PermitRecording::class, 'from'])->args([param('yorvoid.decision_log.permits')]),
            param('yorvoid.decision_log.sample_rate'),
            inline_service('array')->factory([Factories::class, 'recordingPermits'])->args([service(PolicyCatalogue::class)]),
            service('logger')->nullOnInvalid(),
            service(ClockInterface::class),
            inline_service(AuditFailure::class)->factory([AuditFailure::class, 'from'])->args([param('yorvoid.audit.on_failure')]),
            service('event_dispatcher'),
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'flush'])
        ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'flush'])
        ->tag('kernel.event_listener', ['event' => 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'method' => 'flush'])
        ->tag('kernel.event_listener', ['event' => 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'method' => 'flush'])
        ->public();
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

    // The decision point: every operation runs through Operations, which decides and gives its handler a
    // permit. The evaluator decides by YorvoID's ways to authorize and the application's own policy
    // elements (tagged yorvoid.policy_element): the roles' permit, the rules (tagged yorvoid.rule) and the
    // application's own. Abilities are declared (tagged yorvoid.policy_declarations) and decided here, and
    // Symfony's isGranted() asks the evaluator for them too.
    $services->set(OperationCatalogue::class)->factory([OperationCatalogue::class, 'of'])->args([tagged_iterator('yorvoid.operation_handler')])->public();
    $services->set(YorvoIDDeclarations::class)->tag('yorvoid.policy_declarations');
    $services->set(PolicyCatalogue::class)->factory([PolicyCatalogue::class, 'of'])->args([tagged_iterator('yorvoid.policy_declarations')])->public();
    $services->set(GroupRelations::class)->tag('yorvoid.relation_declarations');
    $services->set(RelationSchema::class)->factory([RelationSchema::class, 'of'])->args([tagged_iterator('yorvoid.relation_declarations')])->public();
    $services->set(Relations::class)->public();
    $services->set(PolicyVersion::class)->tag('yorvoid.version_source');
    $services->set(RelationsVersion::class)->tag('yorvoid.version_source');
    $services->set(RelationPermit::class)->tag('yorvoid.policy_element');
    $services->set(RelationChanges::class);
    $services->set(GroupChanges::class);
    $services->set(Attributes::class)->args([tagged_iterator('yorvoid.attribute_declarations'), service(AttributeStore::class), tagged_iterator('yorvoid.attribute_resolver')])->public();
    $services->set(AttributeChanges::class);
    $services->set(OrderedConditions::class)->args([param('yorvoid.ordered_conditions.pessimistic')])->public();
    $services->set(Roles::class)->arg('$sources', tagged_iterator('yorvoid.role_source'))->arg('$relations', service(Relations::class))->public();
    $services->set(SessionStepUpCheck::class);
    $services->alias(StepUpCheck::class, SessionStepUpCheck::class);
    $services->set(RolePermit::class)->arg('$window', inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]))->tag('yorvoid.policy_element');
    $services->set(NoEscalation::class)->tag('yorvoid.policy_element');
    $services->set(PolicyChanges::class);
    $services->set(Views::class);
    $services->set('yorvoid.rule_elements', 'array')->factory([Factories::class, 'ruleElements'])->args([tagged_iterator('yorvoid.rule')]);
    $services->set(StepUpSelfService::class)->arg('$window', inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]));
    // The request's own principal is read once, by its session's check (PERF-07); the evaluator reuses it.
    $services->set(PrincipalAuthority::class)->arg('$loaded', inline_service(Closure::class)->factory([Factories::class, 'loadedPrincipal'])->args([service_closure(CurrentSession::class)]));
    $services->set(Evaluator::class)->factory([Evaluator::class, 'builtIn'])->args([
        service(PrincipalAuthority::class),
        null,
        service(StepUpSelfService::class),
        inline_service('array')->factory([Factories::class, 'elements'])->args([tagged_iterator('yorvoid.policy_element'), service('yorvoid.rule_elements')]),
        null,
        service(ClockInterface::class),
        service('logger')->nullOnInvalid(),
        // Off unless the application registers one: offline decisions are its own build-time choice.
        service(OfflineDecider::class)->nullOnInvalid(),
        tagged_iterator('yorvoid.version_source'),
        service(IdGenerator::class),
        service(DecisionLog::class),
    ]);
    $services->alias(Authorization::class, Evaluator::class);
    $services->set(Operations::class)->arg('$handlers', tagged_iterator('yorvoid.operation_handler'))->public();
    $services->set(Policy::class)->public();
    $services->set(PolicyVoter::class)->tag('security.voter');

    // Listeners of YorvoID's events, through Symfony's dispatcher.
    $services->set(EndSessionsOfInactivePrincipals::class)->tag('kernel.event_listener', ['event' => PrincipalStatusChanging::class]);
    $services->set(ForgetCredentialsOfDeletedPrincipals::class)->tag('kernel.event_listener', ['event' => PrincipalStatusChanging::class]);
    $services->set(ForgetRolesOfDeletedPrincipals::class)->tag('kernel.event_listener', ['event' => PrincipalStatusChanging::class]);
    $services->set(ForgetRelationsOfDeletedPrincipals::class)->tag('kernel.event_listener', ['event' => PrincipalStatusChanging::class]);
    $services->set(ForgetAttributesOfDeletedPrincipals::class)->tag('kernel.event_listener', ['event' => PrincipalStatusChanging::class]);
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
    $services->set(EndSessionOnSignOut::class)->tag('kernel.event_listener', ['event' => LogoutEvent::class]);

    foreach ([PasskeyAuthenticator::class, RecoveryCodeAuthenticator::class, RecoveryLinkAuthenticator::class] as $authenticator) {
        $services->set($authenticator)->arg('$deviceCookie', param('yorvoid.cookies.device'))->public();
    }

    $services->set(CeremonyController::class)->public()->tag('controller.service_arguments')
        ->arg('$window', inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]));

    // Flows: YorvoID's journeys as the application changed them, kept in the PHP session.
    $window = inline_service()->factory([Factories::class, 'duration'])->args([param('yorvoid.step_up.window')]);
    $services->set(SessionFlowStore::class);
    $services->alias(FlowStore::class, SessionFlowStore::class);
    $services->set(PasskeyFlows::class)->arg('$window', $window)->arg('$emailVerification', service(EmailVerification::class)->nullOnInvalid());
    $services->set(Flows::class)->arg('$flows', inline_service()->factory([Factories::class, 'flows'])->args([service(PasskeyFlows::class), tagged_iterator('yorvoid.flow_customizer'), tagged_iterator('yorvoid.flow_source')]))->public();
    $services->set(FlowContexts::class)->arg('$deviceCookie', param('yorvoid.cookies.device'))->public();
    $services->set(SessionAuthenticator::class)->public();
    $services->set(SignInEntryPoint::class);
    $services->set(FlowPages::class)
        ->arg('$translator', service('yorvoid.translator.used'))
        ->arg('$twig', service('twig')->nullOnInvalid())
        ->arg('$script', param('yorvoid.flows.script'))
        ->arg('$remedies', service(RemedyPrompts::class));
    // Refusals, for people (M2-10): after the application's own exception listeners, before Symfony's error page.
    $services->set(RemedyPrompts::class)->arg('$translator', service('yorvoid.translator.used'));
    $services->set(NotPermittedListener::class)->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => NotPermittedListener::PRIORITY]);
    $services->set(FlowController::class)->public()->tag('controller.service_arguments')
        ->arg('$completed', [])
        ->arg('$deviceCookie', param('yorvoid.cookies.device'))
        ->arg('$entrances', inline_service('array')->factory([Factories::class, 'entrances'])->args([tagged_iterator('yorvoid.flow_source')]));

    // The templates: the gallery, the lint, publishing them and what changed since.
    $services->set(TemplateLint::class)->public();
    $services->set(TemplatePublisher::class)->arg('$projectDir', param('kernel.project_dir'))->arg('$source', null);
    $services->set(TemplateGalleryController::class)->public()->tag('controller.service_arguments')->arg('$debug', param('kernel.debug'));

    // The doctor's checks, in the order it runs them. Each is built only when it runs, so that what
    // YorvoID refuses while building one is reported, not fatal.
    $services->set(ConfigurationCheck::class)->args([
        array_map(service_closure(...), [PasskeyPolicy::class, SessionPolicy::class, AttemptLimits::class, Database::class]),
        param('kernel.debug'),
    ])->tag('yorvoid.doctor_check', ['name' => 'Configuration']);
    $services->set(KeysCheck::class)->args([service(KeyFiles::class), service(Realm::class)])->tag('yorvoid.doctor_check', ['name' => 'Keys']);
    $services->set(MigrationsCheck::class)->args([service_closure(Database::class)])->tag('yorvoid.doctor_check', ['name' => 'Database and migrations']);
    $services->set(LogCheckpointsCheck::class)->args([service(KeyFiles::class), param('yorvoid.log.checkpoint_file'), param('yorvoid.log.checkpoint_logger'), service(Realm::class), service(ClockInterface::class), param('kernel.debug')])->tag('yorvoid.doctor_check', ['name' => 'Security log checkpoints']);
    $services->set(CryptoCheck::class)->args([inline_service(CryptoMode::class)->factory([CryptoMode::class, 'from'])->args([param('yorvoid.crypto.mode')]), service_closure(CryptoPolicy::class)])->tag('yorvoid.doctor_check', ['name' => 'Cryptography']);
    $services->set(AuditCheck::class)->args([inline_service(AuditFailure::class)->factory([AuditFailure::class, 'from'])->args([param('yorvoid.audit.on_failure')]), service(DecisionLogStore::class), param('yorvoid.decision_log.permits')])->tag('yorvoid.doctor_check', ['name' => 'Audit failures']);
    $services->set(KeyAlgorithmsCheck::class)->args([service(ConfiguredCheckpoints::class), param('yorvoid.keys.directory')])->public()->tag('yorvoid.doctor_check', ['name' => 'Key algorithms']);
    $services->set(LogGrantsCheck::class)->args([service_closure(Database::class)])->tag('yorvoid.doctor_check', ['name' => 'Security log privileges']);
    $services->set(ClockCheck::class)->arg('$database', service_closure(Database::class))->arg('$debug', param('kernel.debug'))->tag('yorvoid.doctor_check', ['name' => 'Clock']);
    $services->set(AssuranceCheck::class)->args([param('yorvoid.sessions.preset'), param('yorvoid.sessions.absolute'), param('yorvoid.sessions.idle'), param('yorvoid.passkeys.user_verification')])->tag('yorvoid.doctor_check', ['name' => 'Assurance level']);
    $services->set(PresetCheck::class)->args([param('yorvoid.preset'), array_combine(PresetCheck::SETTINGS, array_map(static fn (string $name) => param('yorvoid.'.$name), PresetCheck::SETTINGS))])->tag('yorvoid.doctor_check', ['name' => 'Preset']);
    $services->set(CookiesCheck::class)->args([service('parameter_bag'), param('yorvoid.cookies.device'), param('yorvoid.sessions.recognise_devices'), param('kernel.debug')])->tag('yorvoid.doctor_check', ['name' => 'Cookies']);
    $services->set(ProxiesCheck::class)->args([service('parameter_bag')])->tag('yorvoid.doctor_check', ['name' => 'Proxies']);
    $services->set(MailerCheck::class)->args([param('yorvoid.notifications.enabled'), param('yorvoid.notifications.from'), null, param('kernel.debug')])->tag('yorvoid.doctor_check', ['name' => 'Mailer']);
    $services->set(TemplatesCheck::class)->tag('yorvoid.doctor_check', ['name' => 'Flow pages']);
    $services->set(TestHelpersCheck::class)->args([param('kernel.debug')])->tag('yorvoid.doctor_check', ['name' => 'Test helpers']);
    $services->set(EnvironmentCheck::class)->args([param('kernel.environment'), param('kernel.debug'), param('kernel.secret')])->tag('yorvoid.doctor_check', ['name' => 'Environment']);
    $services->set(KeysRotateCommand::class)->tag('console.command');
    $services->set(ConfiguredCheckpoints::class)->arg('$file', param('yorvoid.log.checkpoint_file'))->arg('$toLogger', param('yorvoid.log.checkpoint_logger'))->arg('$events', service('event_dispatcher'))->arg('$logger', service('logger')->nullOnInvalid());
    $services->set(LogCheckpointCommand::class)->tag('console.command');
    $services->set(LogAnchorCommand::class)->tag('console.command');
    $services->set(LogVerifyCommand::class)->tag('console.command');
    $services->set(DoctorCommand::class)->args([tagged_locator('yorvoid.doctor_check', 'name')])->tag('console.command');

    // Console commands.
    foreach ([MigrateCommand::class, PurgeCommand::class, SetupCodeCommand::class, RecoveryLinkCommand::class, IdentifiersNormalizeCommand::class, TemplatesPublishCommand::class, TemplatesDiffCommand::class, TemplatesLintCommand::class] as $command) {
        $services->set($command)->tag('console.command');
    }

    // The application uses these.
    foreach ([Principals::class, Sessions::class, StepUp::class, Credentials::class, OneTimeTokens::class, Throttle::class, Attempts::class, SetupCodes::class, Invitations::class, RecoveryLinks::class, RecoveryCodes::class, NotMe::class, Passkeys::class, IntentSummaries::class, IntentSigning::class, SecurityLog::class] as $public) {
        $services->get($public)->public();
    }
};
