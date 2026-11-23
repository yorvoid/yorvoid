<?php

declare(strict_types=1);

namespace YorvoID\Symfony\DependencyInjection;

use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Flows\FlowStore;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Policy\Attribute\AttributeStore;
use YorvoID\Policy\PolicyStore;
use YorvoID\Relations\RelationStore;
use YorvoID\Throttle\ThrottleStore;

/**
 * The service ID of each of YorvoID's stores. Stores are for implementing, not calling (@spi): the
 * bridge registers them under these IDs only, never under their interface or class, so application
 * code can't autowire one, and YorvoID's own services get them by binding (config/services.php). A
 * store of the application's own replaces YorvoID's by aliasing its ID ("Stores of your own" in
 * docs/concepts/storage.md, where the list is too).
 *
 * @internal
 */
final class Stores
{
    /** @var array<class-string, string> */
    public const array IDS = [
        PrincipalStore::class => 'yorvoid.store.principal',
        IdentifierStore::class => 'yorvoid.store.identifier',
        SecurityLogStore::class => 'yorvoid.store.security_log',
        SessionStore::class => 'yorvoid.store.session',
        KnownDeviceStore::class => 'yorvoid.store.known_device',
        OneTimeTokenStore::class => 'yorvoid.store.one_time_token',
        SetupStore::class => 'yorvoid.store.setup',
        CredentialStore::class => 'yorvoid.store.credential',
        RecoveryCodeStore::class => 'yorvoid.store.recovery_code',
        CoolDownStore::class => 'yorvoid.store.cool_down',
        PasskeyStore::class => 'yorvoid.store.passkey',
        CeremonyStore::class => 'yorvoid.store.ceremony',
        ThrottleStore::class => 'yorvoid.store.throttle',
        PolicyStore::class => 'yorvoid.store.policy',
        RelationStore::class => 'yorvoid.store.relation',
        AttributeStore::class => 'yorvoid.store.attribute',
        DecisionLogStore::class => 'yorvoid.store.decision_log',
        FlowStore::class => 'yorvoid.store.flow',
        VersionStore::class => 'yorvoid.store.version',
    ];

    private function __construct()
    {
    }
}
