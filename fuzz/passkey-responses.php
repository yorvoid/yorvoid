<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\Storage\InMemoryCredentialStore;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Session\Storage\InMemorySessionStore;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\Storage\InMemoryCoolDownStore;
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
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Storage\InMemoryCeremonyStore;
use YorvoID\Passkeys\Storage\InMemoryPasskeyStore;
use YorvoID\Throttle\Storage\InMemoryThrottleStore;
use YorvoID\Throttle\Throttle;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

require __DIR__.'/bootstrap.php';

// What a browser sends back from navigator.credentials.get(): anything at all. It's refused with
// PasskeyRefused, never with another error, and never signs in. Mutations start from a genuine answer of
// the registered passkey (for an earlier ceremony, so it never works as it is), so they reach deep into
// the parsing and the checks: run.sh runs this in var/fuzz/passkey-responses/, next to the corpus.
$transactions = new InMemoryTransactions();
$log = new SecurityLog(new InMemorySecurityLogStore(), $transactions);
$principals = new Principals(new InMemoryPrincipalStore(), new InMemoryIdentifierStore(), $log, $transactions);
$sessions = new Sessions(new InMemorySessionStore(), $principals, $log, $transactions);
$credentials = new Credentials(new InMemoryCredentialStore(), $sessions, new StepUp($sessions, new InMemoryCoolDownStore(), $log), $log, $transactions);
$passkeys = new Passkeys(new InMemoryPasskeyStore(), new InMemoryCeremonyStore(), $credentials, $sessions, $principals, $log, $transactions, new PasskeyPolicy('example.com', ['https://example.com']), new Attempts(new Throttle(new InMemoryThrottleStore())));
$principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::System));
$session = $sessions->start(new StartSession(Realm::default(), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new DateTimeImmutable()))), new Origin(OriginKind::System))->session;
$authenticator = new VirtualAuthenticator();
$registration = $passkeys->beginRegistration($session);
$passkeys->finishRegistration($session, $registration->id, $authenticator->register($registration->options), 'Fuzz', new Origin(OriginKind::System));

if (is_dir('corpus')) {
    file_put_contents('corpus/genuine-answer.json', $authenticator->signIn($passkeys->beginSignIn(Realm::default())->options));
}

$config->addDictionary(__DIR__.'/passkey-responses.dict');
$config->setTarget(onlyRefusals(static function (string $input) use ($passkeys): void {
    $ceremony = $passkeys->beginSignIn(Realm::default());

    try {
        $passkeys->finishSignIn(Realm::default(), $ceremony->id, $input, new Origin(OriginKind::Web));
    } catch (PasskeyRefused) {
        return;
    }

    throw new LogicException('A made-up answer signed in.');
}));
$config->setMaxLen(2000);
