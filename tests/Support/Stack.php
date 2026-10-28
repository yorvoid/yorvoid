<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Throttle\ThrottleStore;

/**
 * YorvoID's stores and transactions on one implementation: in memory, or a migrated database.
 */
final readonly class Stack
{
    public function __construct(
        public string $implementation,
        public Transactions $transactions,
        public PrincipalStore $principals,
        public IdentifierStore $identifiers,
        public SecurityLogStore $log,
        public SessionStore $sessions,
        public OneTimeTokenStore $tokens,
        public SetupStore $setup,
        public CredentialStore $credentials,
        public PasskeyStore $passkeys,
        public CeremonyStore $ceremonies,
        public RecoveryCodeStore $recoveryCodes,
        public CoolDownStore $coolDowns,
        public ThrottleStore $throttle,
        public KnownDeviceStore $devices,
        public ?Database $database = null,
    ) {
    }
}
