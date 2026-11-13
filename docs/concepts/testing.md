# Testing

Two packages help you test an application that uses YorvoID (PLT-084). Install them for development only:

```bash
composer require --dev yorvoid/testing
```

`yorvoid/testing` brings `yorvoid/virtual-authenticator` with it.

## Scenarios

A `Scenario` makes what your tests need through YorvoID's own services, as people would make it: nothing is inserted behind YorvoID's back, so what a test sets up is what your application sees in production.

```php
use YorvoID\Testing\Scenario;

$client = static::createClient();                     // in a WebTestCase, first: it boots the kernel
$scenario = Scenario::of(static::getContainer());     // in a KernelTestCase, alone

$ana = $scenario->principal('ana@example.com');      // a person with a verified email; a new address if left out
$started = $scenario->session($ana);                  // a session, as signing in with a passkey starts it
$authenticator = $scenario->passkey($ana);            // a passkey registered through the ceremony
$codes = $scenario->recoveryCodes($ana);              // ten recovery codes that work
```

`session()` takes the method to have signed in with, e.g. `AuthenticationMethod::RecoveryCode`: a passkey is multi-factor and phishing-resistant, the others aren't. Outside Symfony, build a `Scenario` from your services with its constructor.

## The clock

YorvoID tells the time by the application's PSR-20 clock. In the test environment, make it one tests can move:

```yaml
# config/services_test.yaml
services:
    clock:
        class: Symfony\Component\Clock\MockClock
        public: true
```

Then `$scenario->clock()->modify('+2 hours')` moves it for YorvoID and your application alike: sessions go idle, tokens expire, cool-downs end. With any other clock, `clock()` says how to set one up. In a `WebTestCase`, call `$client->disableReboot()` first: the test client otherwise boots a new kernel before each request, with a new clock, and the move is lost.

## Signing in in functional tests

```php
use YorvoID\Testing\Symfony\BrowserSignIn;

BrowserSignIn::as($client, $ana);                     // the client from above
$client->request('GET', '/account');                  // signed in as Ana
```

It starts a real YorvoID session and keeps it in the browser's PHP session, as signing in does; the next request signs in through YorvoID's authenticator. Symfony's `loginUser()` would bypass YorvoID, and YorvoID's session check would then sign the user out. Ending the session (`EndSession` through `Operations`) signs the browser out too.

## The virtual authenticator

`VirtualAuthenticator` answers WebAuthn options as a browser with a real authenticator would, and works with any WebAuthn server:

```php
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

$authenticator = new VirtualAuthenticator('https://example.com');
$response = $authenticator->register($creationOptionsJson);   // the PublicKeyCredential, as JSON
$response = $authenticator->signIn($requestOptionsJson);
```

It can be told to misbehave, to test refusals: skip user verification, report a counter, answer for another origin, challenge or relying party. Pass `synced: true` for a synced passkey, `edwards: true` for Ed25519 keys. For tests in real browsers, use the browser's own virtual authenticator.

## Stores of your own

A store you wrote yourself runs YorvoID's tests for its kind, from `YorvoID\Testing\Contract`: see [stores of your own](storage.md#stores-of-your-own).

## Decisions

A refused operation throws `NotPermitted`, whose `decision` has the verdict, the reasons, what couldn't be established and the remedies ([authorization](authorization.md)): assert on those. To explain one, `Explanation::of()` puts together what each element's truth rests on (experimental, [explanations](authorization.md#explanations)). To test your policy itself, as cases, see [test your policy](../how-to/test-your-policy.md) (`yorvoid/policy-testing`).
