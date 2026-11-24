<?php

declare(strict_types=1);

/*
 * A separate process for DelegationConcurrencyTest: revokes a delegation, or passes one on from it, and
 * holds its transaction a while after the change, as a slow request would.
 *
 *   php delegation-race.php <database URL> <schema> revoke <delegation ID> <revoker ID> <start file>
 *   php delegation-race.php <database URL> <schema> issue <parent ID> <delegator ID> <delegate ID> <start file>
 *
 * Exits with 0 once its change is committed. It gives up if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\Delegable;
use YorvoID\Core\Authorization\ListsActions;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\Storage\DbalVersionStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Delegations;
use YorvoID\Delegation\Storage\DbalDelegationStore;
use YorvoID\Tests\Support\Permits;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$given = $_SERVER['argv'];
assert(is_array($given));
$arguments = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $given);
[, $url, $schema, $mode] = $arguments;
$start = $arguments[array_key_last($arguments)];

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware, location: $database->location);
$publish = new class implements ListsActions {
    public function actions(): array
    {
        return ['acme.pages.publish' => Delegable::Everyone];
    }
};
$delegations = new Delegations(new DbalDelegationStore($database, new DbalVersionStore($database)), new DbalPrincipalStore($database), new Actions(new OperationCatalogue([]), [$publish]), new SecurityLog(new DbalSecurityLogStore($database), $transactions), $transactions, roots: new YorvoID\Tests\Support\RootsHold());
$realm = new Realm('acme');
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

$transactions->run(static function () use ($delegations, $realm, $mode, $arguments, $start): void {
    if ('revoke' === $mode) {
        Permits::call($delegations->revoke(...), new RevokeDelegation($realm, DelegationId::fromString($arguments[4]), 'race'), new Origin(OriginKind::Job, 'process '.getmypid()), PrincipalId::fromString($arguments[5]));
    } else {
        Permits::call($delegations->issue(...), new IssueDelegation($realm, PrincipalId::fromString($arguments[6]), ['acme.pages.publish'], new Selector('page'), new DateTimeImmutable('+1 day'), parent: DelegationId::fromString($arguments[4])), new Origin(OriginKind::Job, 'process '.getmypid()), PrincipalId::fromString($arguments[5]));
    }

    // Changed, not yet committed: the other change now must wait for this, and then see it.
    touch($start.'.ready');
    usleep(600_000);
});
