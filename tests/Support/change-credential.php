<?php

declare(strict_types=1);

/*
 * A separate process for CredentialChangeConcurrencyTest: removes or disables one of a principal's
 * credentials, and holds its transaction a while once it has changed it, as a slow request would.
 *
 *   php change-credential.php <database URL> <schema> <remove|disable> <credential ID> <principal ID> <session ID> <start file>
 *
 * Exits with 0 if it went ahead, 3 if it was the principal's last way to sign in. Like append-to-log.php,
 * it gives up if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\DisableCredential;
use YorvoID\Authn\Credential\LastCredential;
use YorvoID\Authn\Credential\RemoveCredential;
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
use YorvoID\Tests\Support\Permits;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 8 === count($arguments));
[, $url, $schema, $action, $credentialId, $principalId, $sessionId, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware, location: $database->location);

$slow = new readonly class(new DbalCredentialStore($database)) implements CredentialStore {
    public function __construct(
        private CredentialStore $store,
    ) {
    }

    public function add(Credential $credential): void
    {
        $this->store->add($credential);
    }

    public function get(Realm $realm, CredentialId $id): ?Credential
    {
        return $this->store->get($realm, $id);
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        return $this->store->of($realm, $principalId);
    }

    public function rename(Realm $realm, CredentialId $id, string $label): bool
    {
        return $this->store->rename($realm, $id, $label);
    }

    public function markUsed(Realm $realm, CredentialId $id, DateTimeImmutable $at): bool
    {
        return $this->store->markUsed($realm, $id, $at);
    }

    public function disable(Realm $realm, CredentialId $id): bool
    {
        $disabled = $this->store->disable($realm, $id);
        usleep(500_000);

        return $disabled;
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        $removed = $this->store->remove($realm, $id);
        usleep(500_000);

        return $removed;
    }
};

$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals(new DbalPrincipalStore($database), new DbalIdentifierStore($database), $log, $transactions);
$sessionStore = new DbalSessionStore($database);
$sessions = new Sessions($sessionStore, $principals, $log, $transactions);
$credentials = new Credentials($slow, $sessions, $principals, new StepUp($sessions, new DbalCoolDownStore($database), $log), $log, $transactions);
$realm = new Realm('acme');
$session = $sessionStore->get($realm, SessionId::fromString($sessionId));
assert(null !== $session);
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

try {
    'remove' === $action
        ? Permits::call($credentials->remove(...), new RemoveCredential($realm, CredentialId::fromString($credentialId)), new Origin(OriginKind::Web), $session)
        : Permits::call($credentials->disable(...), new DisableCredential($realm, CredentialId::fromString($credentialId), PrincipalId::fromString($principalId), 'its principal said they didn\'t add it'), new Origin(OriginKind::Web));
} catch (LastCredential) {
    exit(3);
}
