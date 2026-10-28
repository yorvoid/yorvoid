# Sessions

When someone proves who they are (with a passkey, a setup code, a recovery code), YorvoID starts an **authentication session**. It's kept in your database, and your application's own PHP session (Symfony's, Laravel's, native) holds only its token. Every request checks the token against the database, so ending a session takes effect at the next request, on every server.

Sessions are in the `yorvoid/authn` package.

## Starting a session

The code that checked the proof starts the session, never a request on its own say-so:

```php
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\StartSession;

$started = $sessions->start(
    new StartSession(
        $realm,
        $principal->id,
        new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $clock->now())),
        device: 'Firefox on Linux',      // what the person would recognise in a list of their devices
        address: $request->getClientIp(),
    ),
    new Origin(OriginKind::Web, 'yorvoid_sign_in'),
);

$request->getSession()->set('yorvoid_session', $started->token->reveal());
```

- Only an **active** principal can have a session (`InactivePrincipal` otherwise).
- The token is 256 random bits, URL-safe, and shown only now: YorvoID stores its SHA-256 hash, so a leaked database or backup holds no usable session.
- A listener of `SessionStarting` can refuse the sign-in; `SessionStarted` follows the commit.
- The session's **authentication context** records how and when the person proved who they are, and how strong each proof was: whether it's two factors on its own (a passkey with user verification) and whether it's phishing-resistant. [Stepping up](step-up.md) adds to it, and gives the session a new token; decisions that need a recent or a strong proof read it.
- The security log records the start, the methods and the principal. It doesn't record the address or the device: the log can't forget, and both are personal data. They're kept with the session, which is purged.

## Checking a session on each request

```php
$session = $sessions->validate($realm, Secret::fromString($request->getSession()->get('yorvoid_session')));
```

`validate()` returns the session, or `null` when:

- the token is unknown, or belongs to another realm;
- the session ended, or is past its absolute or idle limit;
- its principal isn't active, **or changed status since the session started**. A suspended principal's sessions don't come back when the suspension is undone: they sign in again.

It also records that the session was used, at most once a minute, which extends its idle limit.

After signing in again or stepping up, give the session a new token with `refresh()`: the old one stops working (protection against session fixation).

## How long sessions last

A `SessionPolicy` sets the absolute limit (since signing in) and the idle limit (since the last request):

| Preset | Absolute | Idle | |
|---|---|---|---|
| `SessionPolicy::aal2()` (the default) | 24 hours | 1 hour | NIST SP 800-63B-4's limits for AAL2 |
| `SessionPolicy::aal3()` | 12 hours | 15 minutes | NIST SP 800-63B-4's limits for AAL3 |
| `new SessionPolicy(Duration::hours(8), Duration::hours(1))` | 8 hours | 1 hour | e.g. an administration console |

Each session keeps the limits it started with, and the current policy applies too: tightening the policy ends sessions that are past the new limits at their next request.

Document your choice, and why, if it's longer than NIST's (OWASP ASVS 7.1.1).

## Ending sessions

```php
// Signing out
$sessions->end(new EndSession($realm, $session->id, SessionEndReason::SignedOut), $origin);

// "Sign out everywhere else"
$sessions->endAll(new EndSessions($realm, $principalId, SessionEndReason::EndedByPrincipal, except: $session->id), $origin, $principalId);

// An administrator ends someone's sessions
$sessions->endAll(new EndSessions($realm, $principalId, SessionEndReason::EndedByAdministrator), $origin, $administratorId);
```

`current($realm, $principalId)` lists a principal's sessions, the most recently used first, for a "your devices" page or an administrator's.

Ending someone else's sessions needs the ability `yorvoid.sessions.manage`; anyone may end their own. Ask for a recent authentication before either (OWASP ASVS 7.5.2): the framework bridges do.

Each ended session gets a log entry with the reason, and a `SessionEnded` event.

### When a principal is suspended or deleted

Register `EndSessionsOfInactivePrincipals` as a listener of `PrincipalStatusChanging`: a principal's sessions then end in the same transaction as its suspension or deletion, and lists are right at once. (Without it, those sessions are refused anyway.)

## Recognising devices

With a `KnownDeviceStore` (`new Sessions(..., devices: new DbalKnownDeviceStore($database))`), sessions recognise the devices people sign in from (SES-012) by a cookie, never by fingerprinting:

```php
$started = $sessions->start(new StartSession($realm, $principalId, $authentication, deviceCookie: $fromTheCookie), $origin);
// The services that sign in take it as deviceCookie: too, e.g. $passkeys->finishSignIn(...).

$response->headers->setCookie(Cookie::create('__Host-yorvoid_device', $started->deviceCookie->reveal(), '+400 days', secure: true, httpOnly: true, sameSite: 'lax'));
```

- `$started->deviceCookie` is the cookie to set: the one sent, or a new one (256 random bits). Only its SHA-256 hash is kept, per principal.
- `$started->newDevice` says whether the principal signed in from a device it hasn't used before, while it has used others. Then `NewDeviceSignedIn` follows the commit, and [security notifications](notifications.md) tell the person.
- A first sign-in is no news; nor is one account's device to another account.
- Forget devices not used for a while daily, with a period longer than the cookie's lifetime: `$sessions->purgeDevices(Duration::days(400))`.

## Purging old sessions

Ended and expired sessions stay until you purge them, so that "your devices" and investigations can see recent ones. Purge daily, e.g. from a scheduled job:

```php
$sessions->purge(Duration::days(30)); // removes sessions that ended or expired over 30 days ago
```
