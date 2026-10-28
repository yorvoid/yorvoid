<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\Setup\Storage\InMemorySetupStore;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Token\Storage\InMemoryOneTimeTokenStore;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\Storage\InMemoryIdentifierStore;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Throttle\Storage\InMemoryThrottleStore;
use YorvoID\Throttle\Throttle;

require __DIR__.'/bootstrap.php';

// What arrives in a link or is typed as a setup code: anything at all. It never works (a guess that
// does would be a 2^-128 event), and nothing breaks on the way to saying so.
$transactions = new InMemoryTransactions();
$log = new SecurityLog(new InMemorySecurityLogStore(), $transactions);
$principals = new Principals(new InMemoryPrincipalStore(), new InMemoryIdentifierStore(), $log, $transactions);
$tokens = new OneTimeTokens(new InMemoryOneTimeTokenStore(), $principals, $log, $transactions);
$setup = new SetupCodes(new InMemorySetupStore(), $principals, $log, $transactions, new Attempts(new Throttle(new InMemoryThrottleStore())));
$principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::System));
$tokens->issue(new IssueToken(Realm::default(), $principal->id, 'fuzz.test', Duration::days(1)), new Origin(OriginKind::System));
$setup->create(new CreateSetupCode(Realm::default(), Duration::days(1)), new Origin(OriginKind::System));

$config->setTarget(onlyRefusals(static function (string $input) use ($tokens, $setup): void {
    if ('' === $input) {
        return;
    }

    if (null !== $tokens->peek(Realm::default(), 'fuzz.test', Secret::fromString($input))) {
        throw new LogicException('A made-up token worked.');
    }

    try {
        $setup->claim(Realm::default(), Secret::fromString($input), new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));
    } catch (InvalidSetupCode) {
        return;
    }

    throw new LogicException('A made-up setup code worked.');
}));
$config->setMaxLen(120);
