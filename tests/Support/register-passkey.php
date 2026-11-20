<?php

declare(strict_types=1);

/*
 * A separate process for PasskeyRegistrationConcurrencyTest: finishes a passkey's registration, and holds
 * its transaction a while once the passkey is stored, as a slow request would.
 *
 *   php register-passkey.php <database URL> <schema> <session ID> <ceremony ID> <response file> <start file>
 *
 * Exits with 0 if the passkey was registered, 3 if it was refused. Like append-to-log.php, it gives up
 * if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Passkeys\Passkey;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Passkeys\RegisterPasskey;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;
use YorvoID\Tests\Support\Permits;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 7 === count($arguments));
[, $url, $schema, $sessionId, $ceremonyId, $responseFile, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware, location: $database->location);

$slow = new readonly class(new DbalPasskeyStore($database), $start.'.ready') implements PasskeyStore {
    public function __construct(
        private PasskeyStore $store,
        private string $ready,
    ) {
    }

    public function add(Passkey $passkey): bool
    {
        $added = $this->store->add($passkey);
        touch($this->ready);
        usleep(500_000);

        return $added;
    }

    public function find(Realm $realm, string $webauthnId): ?Passkey
    {
        return $this->store->find($realm, $webauthnId);
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        return $this->store->of($realm, $principalId);
    }

    public function update(Passkey $passkey): bool
    {
        return $this->store->update($passkey);
    }

    public function remove(Realm $realm, CredentialId $credentialId): void
    {
        $this->store->remove($realm, $credentialId);
    }
};

$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals(new DbalPrincipalStore($database), new DbalIdentifierStore($database), $log, $transactions);
$sessionStore = new DbalSessionStore($database);
$sessions = new Sessions($sessionStore, $principals, $log, $transactions);
$credentials = new Credentials(new DbalCredentialStore($database), $sessions, $principals, new StepUp($sessions, new DbalCoolDownStore($database), $log), $log, $transactions);
$passkeys = new Passkeys($slow, new DbalCeremonyStore($database), $credentials, $sessions, $principals, $log, $transactions, new PasskeyPolicy('example.com', ['https://example.com']), new Attempts(new Throttle(new DbalThrottleStore($database))));
$realm = new Realm('acme');
$session = $sessionStore->get($realm, SessionId::fromString($sessionId));
assert(null !== $session);
$response = file_get_contents($responseFile);
assert(is_string($response));
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

try {
    Permits::call($passkeys->finishRegistration(...), new RegisterPasskey($realm, $ceremonyId, $response, 'Phone'), new Origin(OriginKind::Web), $session);
} catch (PasskeyRefused) {
    exit(3);
}
