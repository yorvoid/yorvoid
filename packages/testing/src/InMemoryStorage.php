<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use YorvoID\Authn\Credential\Storage\InMemoryCredentialStore;
use YorvoID\Authn\Recovery\Storage\InMemoryRecoveryCodeStore;
use YorvoID\Authn\Session\Storage\InMemoryKnownDeviceStore;
use YorvoID\Authn\Session\Storage\InMemorySessionStore;
use YorvoID\Authn\Setup\Storage\InMemorySetupStore;
use YorvoID\Authn\StepUp\Storage\InMemoryCoolDownStore;
use YorvoID\Authn\Token\Storage\InMemoryOneTimeTokenStore;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Principal\Storage\InMemoryIdentifierStore;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Passkeys\Storage\InMemoryCeremonyStore;
use YorvoID\Passkeys\Storage\InMemoryPasskeyStore;
use YorvoID\Policy\Storage\InMemoryAttributeStore;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Throttle\Storage\InMemoryThrottleStore;

/**
 * Every in-memory store, each registered with the transactions: a failed unit of work then undoes
 * what it did in all of them, as a database's rollback does. YorvoID's guarantees rely on it, e.g. the
 * last way to sign in can't be removed. Build them this way rather than one by one, where a store
 * left unregistered keeps half a change.
 *
 * The throttle's counts aren't registered: an attempt counts even when its work is undone.
 *
 * @api
 */
final readonly class InMemoryStorage
{
    public InMemoryTransactions $transactions;

    public InMemoryPrincipalStore $principals;

    public InMemoryIdentifierStore $identifiers;

    public InMemorySecurityLogStore $log;

    public InMemorySessionStore $sessions;

    public InMemoryKnownDeviceStore $devices;

    public InMemoryOneTimeTokenStore $tokens;

    public InMemorySetupStore $setup;

    public InMemoryCredentialStore $credentials;

    public InMemoryRecoveryCodeStore $recoveryCodes;

    public InMemoryCoolDownStore $coolDowns;

    public InMemoryPasskeyStore $passkeys;

    public InMemoryCeremonyStore $ceremonies;

    public InMemoryPolicyStore $policy;

    public InMemoryRelationStore $relations;

    public InMemoryAttributeStore $attributes;

    public InMemoryThrottleStore $throttle;

    public function __construct()
    {
        $this->transactions = new InMemoryTransactions();

        $this->principals = new InMemoryPrincipalStore();
        $this->identifiers = new InMemoryIdentifierStore();
        $this->log = new InMemorySecurityLogStore();
        $this->sessions = new InMemorySessionStore();
        $this->devices = new InMemoryKnownDeviceStore();
        $this->tokens = new InMemoryOneTimeTokenStore();
        $this->setup = new InMemorySetupStore();
        $this->credentials = new InMemoryCredentialStore();
        $this->recoveryCodes = new InMemoryRecoveryCodeStore();
        $this->coolDowns = new InMemoryCoolDownStore();
        $this->passkeys = new InMemoryPasskeyStore();
        $this->ceremonies = new InMemoryCeremonyStore();
        $this->transactions->register($this->principals);
        $this->transactions->register($this->identifiers);
        $this->transactions->register($this->log);
        $this->transactions->register($this->sessions);
        $this->transactions->register($this->devices);
        $this->transactions->register($this->tokens);
        $this->transactions->register($this->setup);
        $this->transactions->register($this->credentials);
        $this->transactions->register($this->recoveryCodes);
        $this->transactions->register($this->coolDowns);
        $this->transactions->register($this->passkeys);
        $this->transactions->register($this->ceremonies);
        $this->transactions->register($this->policy = new InMemoryPolicyStore());
        $this->transactions->register($this->relations = new InMemoryRelationStore());
        $this->transactions->register($this->attributes = new InMemoryAttributeStore());
        $this->throttle = new InMemoryThrottleStore();
    }
}
