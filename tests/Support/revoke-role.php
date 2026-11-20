<?php

declare(strict_types=1);

/*
 * A separate process for LastAdministratorConcurrencyTest: takes a role from a principal, and holds its
 * transaction a while after the change, as a slow request would.
 *
 *   php revoke-role.php <database URL> <schema> <principal ID> <role> <start file>
 *
 * Exits with 0 once the role is taken and committed. Like append-to-log.php, it gives up if the start
 * file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyChanges;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Tests\Support\Permits;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 6 === count($arguments));
[, $url, $schema, $id, $role, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware, location: $database->location);
$changes = new PolicyChanges(PolicyCatalogue::of([new YorvoIDDeclarations()]), new DbalPolicyStore($database), new DbalPrincipalStore($database), new SecurityLog(new DbalSecurityLogStore($database), $transactions), $transactions);
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

$transactions->run(static function () use ($changes, $id, $role, $start): void {
    Permits::call($changes->operations()[UnassignRole::class], new UnassignRole(new Realm('acme'), PrincipalId::fromString($id), $role), new Origin(OriginKind::Job, 'process '.getmypid()));
    // Taken, not yet committed: a suspension's guard rule now must wait for this, or count it.
    touch($start.'.ready');
    usleep(600_000);
});
