<?php

declare(strict_types=1);

/*
 * A separate process for ReplacementConcurrencyTest: replaces a principal's recovery codes, or issues it
 * a recovery link, which replaces the one before.
 *
 *   php replace-secrets.php <database URL> <schema> codes <session ID> <marker file> <hold ms>
 *   php replace-secrets.php <database URL> <schema> link <principal ID> <marker file> <hold ms>
 *
 * It touches the marker file once it has replaced them, still inside its transaction, then holds the
 * transaction open for that long before committing. It prints the new codes, or the link's token, as
 * JSON.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
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
use YorvoID\Tests\Support\Permits;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 7 === count($arguments));
[, $url, $schema, $kind, $id, $marker, $hold] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$transactions = new DbalTransactions($connection, $middleware);
$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals(new DbalPrincipalStore($database), new DbalIdentifierStore($database), $log, $transactions);
$sessions = new Sessions(new DbalSessionStore($database), $principals, $log, $transactions);
$stepUp = new StepUp($sessions, new DbalCoolDownStore($database), $log);
$origin = new Origin(OriginKind::Web, 'process '.getmypid());

$replace = match ($kind) {
    'codes' => static function () use ($database, $sessions, $stepUp, $log, $transactions, $principals, $id, $origin): array {
        $codes = new RecoveryCodes(new DbalRecoveryCodeStore($database), new Credentials(new DbalCredentialStore($database), $sessions, $stepUp, $log, $transactions), $sessions, $stepUp, new Attempts(new Throttle(new DbalThrottleStore($database))), $log, $transactions, $principals);
        $session = $sessions->get(new Realm('acme'), SessionId::fromString($id)) ?? throw new LogicException('No such session.');

        return array_map(static fn (Secret $code): string => $code->reveal(), Permits::call($codes->generate(...), new GenerateRecoveryCodes(new Realm('acme')), $origin, $session));
    },
    'link' => static function () use ($database, $sessions, $stepUp, $log, $transactions, $principals, $id, $origin): array {
        $links = new RecoveryLinks(new OneTimeTokens(new DbalOneTimeTokenStore($database), $principals, $log, $transactions), $sessions, $principals, $stepUp, $transactions);

        return [Permits::call($links->issue(...), new IssueRecoveryLink(new Realm('acme'), PrincipalId::fromString($id)), $origin)->secret->reveal()];
    },
    default => throw new LogicException(sprintf('Nothing to replace called "%s".', $kind)),
};

echo json_encode($transactions->run(static function () use ($replace, $marker, $hold): array {
    $secrets = $replace();
    touch($marker);
    usleep(1_000 * (int) $hold);

    return $secrets;
}), \JSON_THROW_ON_ERROR);
