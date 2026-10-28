<?php

declare(strict_types=1);

/*
 * A separate process for GuardRuleConcurrencyTest: suspends one principal, under a guard rule that keeps
 * at least one of a group of principals active.
 *
 *   php suspend-principal.php <database URL> <schema> <principal ID> <group's IDs, comma-separated> <start file>
 *
 * Exits with 0 if the suspension went ahead, 3 if the guard rule refused it. The rule pauses before it
 * reads, so that both processes are inside their transactions at once. Like append-to-log.php, it gives
 * up if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\GuardRule;
use YorvoID\Core\Principal\GuardRuleViolated;
use YorvoID\Core\Principal\PrincipalChange;
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
use YorvoID\Core\Storage\StorageLocation;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 6 === count($arguments));
[, $url, $schema, $id, $group, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware);
$store = new DbalPrincipalStore($database);

$keepOneActive = new readonly class($store, array_map(PrincipalId::fromString(...), explode(',', $group))) implements GuardRule {
    /**
     * @param list<PrincipalId> $group
     */
    public function __construct(
        private PrincipalStore $store,
        private array $group,
    ) {
    }

    public function check(PrincipalChange $change): ?string
    {
        usleep(300_000);

        foreach ($this->group as $member) {
            if (true === $this->store->get($change->after->realm, $member)?->isActive()) {
                return null;
            }
        }

        return 'At least one owner stays active.';
    }
};

$principals = new Principals($store, new DbalIdentifierStore($database), new SecurityLog(new DbalSecurityLogStore($database), $transactions), $transactions, guardRules: [$keepOneActive]);
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

try {
    $principals->suspend(new SuspendPrincipal(new Realm('acme'), PrincipalId::fromString($id)), new Origin(OriginKind::Job, 'process '.getmypid()));
} catch (GuardRuleViolated) {
    exit(3);
}
