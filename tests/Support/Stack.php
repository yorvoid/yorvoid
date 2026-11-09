<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Migrations\AuthnMigrations;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Session\Storage\DbalKnownDeviceStore;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\Setup\Storage\DbalSetupStore;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\Migrations\PasskeysMigrations;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;
use YorvoID\Policy\Attribute\AttributeStore;
use YorvoID\Policy\Migrations\PolicyMigrations;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\DbalAttributeStore;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Relations\Migrations\RelationsMigrations;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\Storage\DbalRelationStore;
use YorvoID\Throttle\Migrations\ThrottleMigrations;
use YorvoID\Throttle\Storage\DbalThrottleStore;
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
        public PolicyStore $policy,
        public RelationStore $relations,
        public AttributeStore $attributes,
        public ?Database $database = null,
        public DecisionLogStore $decisions = new InMemoryDecisionLogStore(),
    ) {
    }

    /**
     * YorvoID's stores on the database, migrated: for the tests and the benchmarks (tools/bench).
     *
     * @param TransactionMiddleware $middleware The one on the database's connection
     */
    public static function onDatabase(string $implementation, Database $database, TransactionMiddleware $middleware): self
    {
        new Migrator($database, [...CoreMigrations::all(), ...AuthnMigrations::all(), ...PasskeysMigrations::all(), ...PolicyMigrations::all(), ...RelationsMigrations::all(), ...ThrottleMigrations::all()])->migrate();

        return new self($implementation, new DbalTransactions($database->connection, $middleware), new DbalPrincipalStore($database), new DbalIdentifierStore($database), new DbalSecurityLogStore($database), new DbalSessionStore($database), new DbalOneTimeTokenStore($database), new DbalSetupStore($database), new DbalCredentialStore($database), new DbalPasskeyStore($database), new DbalCeremonyStore($database), new DbalRecoveryCodeStore($database), new DbalCoolDownStore($database), new DbalThrottleStore($database), new DbalKnownDeviceStore($database), new DbalPolicyStore($database), new DbalRelationStore($database), new DbalAttributeStore($database), $database, new DbalDecisionLogStore($database));
    }
}
