# Passkeys in any PHP application

YorvoID's packages don't need a framework: they're services you build once and call from your own controllers, whatever routes them. This tutorial wires them by hand and walks through claiming the first account, adding a passkey, signing in and out. With Symfony, the [bundle](symfony-quickstart.md) does all of it for you.

The code on this page runs as it is: a test strings its PHP blocks together, with a virtual authenticator in place of the browser, and checks every step.

## Install

```bash
composer require yorvoid/passkeys
```

It brings the core, authentication, throttling and Doctrine DBAL with it.

## Build the services

Do this once per request, or once per worker in a long-running server. YorvoID keeps no state of its own between calls: everything is in the database.

```php
<?php

require 'vendor/autoload.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\Setup\Storage\DbalSetupStore;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;

// Your connection, with YorvoID's middleware, so its events wait for your commits.
$middleware = new TransactionMiddleware();
$connection = DriverManager::getConnection(
    ['driver' => 'pdo_sqlite', 'path' => __DIR__.'/yorvoid.sqlite'],
    new Configuration()->setMiddlewares([$middleware]),
);
$database = new Database($connection);
$transactions = new DbalTransactions($connection, $middleware);

$realm = Realm::default();
$log = new SecurityLog(new DbalSecurityLogStore($database), $transactions);
$principals = new Principals(new DbalPrincipalStore($database), new DbalIdentifierStore($database), $log, $transactions);
$sessions = new Sessions(new DbalSessionStore($database), $principals, $log, $transactions, SessionPolicy::aal2());
$attempts = new Attempts(new Throttle(new DbalThrottleStore($database)));
$setupCodes = new SetupCodes(new DbalSetupStore($database), $principals, $log, $transactions, $attempts);
$stepUp = new StepUp($sessions, new DbalCoolDownStore($database), $log);
$credentials = new Credentials(new DbalCredentialStore($database), $sessions, $stepUp, $log, $transactions);
$passkeys = new Passkeys(
    new DbalPasskeyStore($database),
    new DbalCeremonyStore($database),
    $credentials,
    $sessions,
    $principals,
    $log,
    $transactions,
    new PasskeyPolicy('example.com', ['https://example.com']),
    $attempts,
);
```

- **The passkey policy** names the domain passkeys belong to, and the origins of the pages that use them ([passkeys](../concepts/passkeys.md#configuration)).
- **PostgreSQL** in production: change the connection's parameters; the rest stays ([storage](../concepts/storage.md)).

## Create the tables

From your deployment, not from a request:

```php
use YorvoID\Authn\Migrations\AuthnMigrations;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Passkeys\Migrations\PasskeysMigrations;
use YorvoID\Throttle\Migrations\ThrottleMigrations;

new Migrator($database, [...CoreMigrations::all(), ...AuthnMigrations::all(), ...PasskeysMigrations::all(), ...ThrottleMigrations::all()])->migrate();
```

## Claim the first account

A fresh installation has no accounts. Whoever has the server's shell creates a setup code, from a console script:

```php
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;

$code = $setupCodes->create(new CreateSetupCode($realm, Duration::days(1)), new Origin(OriginKind::Console));
echo $code->reveal(), "\n";
```

<!-- test
$_POST = ['code' => $code->reveal(), 'email' => 'ana@example.com'];
-->

Your setup page takes the code and an email address, claims the installation, and signs the owner in:

```php
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Secret;

$owner = $setupCodes->claim(
    $realm,
    Secret::fromString($_POST['code']),
    new CreatePrincipal($realm, PrincipalKind::Human, email: $_POST['email'], emailVerified: true),
    new Origin(OriginKind::Web),
    $_SERVER['REMOTE_ADDR'] ?? null,
);
$started = $sessions->start(
    new StartSession($realm, $owner->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new DateTimeImmutable()))),
    new Origin(OriginKind::Web),
);
setcookie('__Host-session', $started->token->reveal(), ['secure' => true, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
```

The session's token is a random secret: YorvoID keeps only its hash, so the cookie is the only copy. A wrong or used code throws `InvalidSetupCode`; too many attempts throw `Throttled` with the seconds to wait.

## On every request: who is signed in

<!-- test
$_COOKIE = ['__Host-session' => $started->token->reveal()];
-->

```php
$session = isset($_COOKIE['__Host-session'])
    ? $sessions->validate($realm, Secret::fromString($_COOKIE['__Host-session']))
    : null;
```

`$session` is `null` when there's no cookie, or its session ended, expired, or its principal was suspended: treat the request as signed out. Otherwise `$session->principalId` is who it is.

## Add a passkey

The signed-in owner adds a passkey. Your server starts the ceremony and sends its options to the browser:

```php
$ceremony = $passkeys->beginRegistration($session);
// Answer with JSON: {"ceremony": $ceremony->id, "options": $ceremony->options}
```

The browser creates the passkey with the WebAuthn API, and posts it back:

```js
const { ceremony, options } = await (await fetch('/passkeys/options', { method: 'POST' })).json();
const credential = await navigator.credentials.create({ publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options) });
await fetch('/passkeys', { method: 'POST', body: JSON.stringify({ ceremony, response: credential.toJSON() }) });
```

<!-- test
$authenticator = new YorvoID\VirtualAuthenticator\VirtualAuthenticator('https://example.com');
$_POST = ['ceremony' => $ceremony->id, 'response' => $authenticator->register($ceremony->options)];
-->

Your server finishes it with what the browser sent, the `response` as JSON:

```php
$passkeys->finishRegistration($session, $_POST['ceremony'], $_POST['response'], 'Laptop', new Origin(OriginKind::Web));
```

YorvoID checks the answer against the ceremony: the challenge, the origin, the relying party, user verification. A refused answer throws `PasskeyRefused` with the reason.

## Sign out

```php
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\SessionEndReason;

$sessions->end(new EndSession($realm, $session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
setcookie('__Host-session', '', ['expires' => 1, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
```

<!-- test
assert(null === $sessions->validate($realm, Secret::fromString($_COOKIE['__Host-session'])), 'The session outlived signing out.');
-->

## Sign in with the passkey

```php
$ceremony = $passkeys->beginSignIn($realm);
// Answer with JSON: {"ceremony": $ceremony->id, "options": $ceremony->options}
```

```js
const { ceremony, options } = await (await fetch('/sign-in/options', { method: 'POST' })).json();
const credential = await navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options) });
await fetch('/sign-in', { method: 'POST', body: JSON.stringify({ ceremony, response: credential.toJSON() }) });
```

<!-- test
$_POST = ['ceremony' => $ceremony->id, 'response' => $authenticator->signIn($ceremony->options)];
-->

```php
$started = $passkeys->finishSignIn($realm, $_POST['ceremony'], $_POST['response'], new Origin(OriginKind::Web), address: $_SERVER['REMOTE_ADDR'] ?? null);
setcookie('__Host-session', $started->token->reveal(), ['secure' => true, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
```

A passkey sign-in starts a multi-factor, phishing-resistant session. One that doesn't work throws `PasskeyRefused` with the reason, e.g. a passkey that isn't registered here, so the browser can tell its provider; too many attempts from the address throw `Throttled`.

<!-- test
assert($started->session->principalId->equals($owner->id), 'Someone else signed in.');
echo "The tutorial works.\n";
-->

## What's next

- **The browser:** the [browser client](../concepts/browser-client.md) does the JavaScript above, with autofill and errors to show.
- **Journeys:** [flows](../concepts/flows.md) run sign-in, invitations and recovery as server-side steps.
- **Recovery:** [recovery codes](../concepts/recovery-codes.md), so a lost device isn't a lost account.
- **Events:** pass your PSR-14 dispatcher to the services to hear what happens ([operations and events](../concepts/operations-and-events.md)).
