<?php

declare(strict_types=1);

/*
 * A separate process for RecoveryCodeConcurrencyTest: recovers an account with a recovery code.
 *
 *   php use-recovery-code.php <database URL> <schema> <principal ID> <code> <start file>
 *
 * Exits with 0 if the code worked, 3 if it didn't. Listing the principal's credentials, before using the
 * code, pauses, so that every process has found the set before any uses a code. Like consume-token.php,
 * it gives up if the start file doesn't appear.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
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
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 6 === count($arguments));
[, $url, $schema, $principalId, $code, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware);

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
        usleep(300_000);

        return $this->store->of($realm, $principalId);
    }

    public function update(Credential $credential): void
    {
        $this->store->update($credential);
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        return $this->store->remove($realm, $id);
    }
};

$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals(new DbalPrincipalStore($database), new DbalIdentifierStore($database), $log, $transactions);
$sessions = new Sessions(new DbalSessionStore($database), $principals, $log, $transactions);
$stepUp = new StepUp($sessions, new DbalCoolDownStore($database), $log);
$codes = new RecoveryCodes(new DbalRecoveryCodeStore($database), new Credentials($slow, $sessions, $stepUp, $log, $transactions), $sessions, $stepUp, new Attempts(new Throttle(new DbalThrottleStore($database))), $log, $transactions);
$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

exit(null === $codes->redeem(new Realm('acme'), PrincipalId::fromString($principalId), Secret::fromString($code), new Origin(OriginKind::Web, 'process '.getmypid())) ? 3 : 0);
