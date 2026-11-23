<?php

declare(strict_types=1);

/*
 * A separate process for SessionStartConcurrencyTest: starts a session for a principal, or suspends it,
 * and holds the principal's lock a while once it has it, as a slow request would.
 *
 *   php session-or-suspend.php <database URL> <schema> <principal ID> <start|suspend> <isolation> <start file>
 *
 * The isolation is "read committed" or "repeatable read". Exits with 0 if it went ahead, 3 if the
 * principal wasn't active to start a session, 4 if it conflicted. Like append-to-log.php, it gives up
 * if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSessionsOfInactivePrincipals;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\RecordingDispatcher;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 7 === count($arguments));
[, $url, $schema, $id, $action, $isolation, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$connection->executeStatement(sprintf('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL %s', 'repeatable read' === $isolation ? 'REPEATABLE READ' : 'READ COMMITTED'));
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware, location: $database->location);

$slow = new readonly class(new DbalPrincipalStore($database), $start.'.ready') implements PrincipalStore {
    public function __construct(
        private PrincipalStore $store,
        private string $ready,
    ) {
    }

    public function add(Principal $principal): void
    {
        $this->store->add($principal);
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        return $this->store->get($realm, $id);
    }

    public function hasActive(Realm $realm): bool
    {
        return $this->store->hasActive($realm);
    }

    public function lock(Realm $realm, PrincipalId $id): void
    {
        $this->store->lock($realm, $id);
        touch($this->ready);
        usleep(500_000);
    }

    public function ownsAgents(Realm $realm, PrincipalId $owner): bool
    {
        return $this->store->ownsAgents($realm, $owner);
    }

    public function changeOwner(Principal $before, Principal $after): bool
    {
        return $this->store->changeOwner($before, $after);
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        return $this->store->changeStatus($before, $after);
    }
};

$events = new RecordingDispatcher();
$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals($slow, new DbalIdentifierStore($database), $log, $transactions, $events);
$sessions = new Sessions(new DbalSessionStore($database), $principals, $log, $transactions);
$events->listen(PrincipalStatusChanging::class, new EndSessionsOfInactivePrincipals($sessions)(...));
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

$realm = new Realm('acme');
$principalId = PrincipalId::fromString($id);

try {
    'start' === $action
        ? Permits::call($sessions->start(...), new StartSession($realm, $principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new DateTimeImmutable(), true, true))), new Origin(OriginKind::Web))
        : Permits::call($principals->suspend(...), new SuspendPrincipal($realm, $principalId), new Origin(OriginKind::Web));
} catch (InactivePrincipal) {
    exit(3);
} catch (RetryableConflict) {
    exit(4);
}
