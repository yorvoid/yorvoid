<?php

declare(strict_types=1);

/*
 * A separate process for OneTimeTokenConcurrencyTest: uses a one-time token.
 *
 *   php consume-token.php <database URL> <schema> <token> <start file>
 *
 * Exits with 0 if the token worked, 3 if it didn't. Reading the principal, between finding the token and
 * using it, pauses, so that every process has found the token before any uses it. Like
 * append-to-log.php, it gives up if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
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

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 5 === count($arguments));
[, $url, $schema, $token, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

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
        usleep(300_000);

        return $this->store->get($realm, $id);
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        return $this->store->changeStatus($before, $after);
    }
};

$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$tokens = new OneTimeTokens(new DbalOneTimeTokenStore($database), new Principals($slow, new DbalIdentifierStore($database), $log, $transactions), $log, $transactions);
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

exit(null === $tokens->consume(new Realm('acme'), 'acme.test', Secret::fromString($token), new Origin(OriginKind::Web, 'process '.getmypid())) ? 3 : 0);
