<?php

declare(strict_types=1);

/*
 * A separate process for SetupClaimConcurrencyTest: claims the realm "acme" with a setup code.
 *
 *   php claim-setup.php <database URL> <schema> <code> <start file>
 *
 * Exits with 0 if the claim worked, 3 if the code was refused. Asking whether the realm has an active
 * principal pauses after the answer, so that another principal can be created between the question and
 * the claim. Like consume-token.php, it gives up if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\Setup\ClaimSetup;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\Setup\Storage\DbalSetupStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Tests\Support\Permits;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 5 === count($arguments));
[, $url, $schema, $code, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware);

$slow = new readonly class(new DbalPrincipalStore($database)) implements PrincipalStore {
    public function __construct(
        private PrincipalStore $store,
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
        $answer = $this->store->hasActive($realm);
        usleep(600_000);

        return $answer;
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        return $this->store->changeStatus($before, $after);
    }
};

$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals($slow, new DbalIdentifierStore($database), $log, $transactions);
$codes = new SetupCodes(new DbalSetupStore($database), $principals, $log, $transactions, new Attempts(new Throttle(new DbalThrottleStore($database))), new Sessions(new DbalSessionStore($database), $principals, $log, $transactions));
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

try {
    Permits::call($codes->claim(...), new ClaimSetup(new Realm('acme'), Secret::fromString($code), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human)), new Origin(OriginKind::Web, 'process '.getmypid()));
} catch (InvalidSetupCode) {
    exit(3);
}
