# Integrate without a framework bridge

The [Symfony bridge](../concepts/symfony.md) does more than wire services: it does security work at the edges, where YorvoID's services meet HTTP. In Laravel, Drupal, Slim or plain PHP, that work is yours. Each item below says what the bridge does, why, and what to do instead. The [plain-PHP tutorial](../tutorials/plain-php.md) shows several of them in running code.

## Holding the session

**A new session ID whenever you store a YorvoID token.** If the token goes into your framework's session, give that session a new ID first, on sign-in and whenever a [step-up](../concepts/step-up.md) renews the token. Otherwise someone who planted a session cookie in the person's browser before they signed in shares the signed-in session afterwards (session fixation).

```php
session_regenerate_id(true);                  // plain PHP sessions
$request->session()->regenerate(true);        // Laravel
$request->getSession()->migrate(true);        // Symfony HttpFoundation
// then: store $started->token->reveal()
```

After a step-up, keep the old session rather than destroy it (`session_regenerate_id(false)`, `migrate(false)`): it holds the old token, which no longer works, and a request from another tab that was already waiting on it then doesn't delete the new session's cookie, signing the person out.

**End the session the browser held before.** Signing in again, as the same person or someone else, starts a new YorvoID session; end the one your session held, with `SessionEndReason::Replaced`, unless the token you're storing is a step-up's for the same session. Otherwise it stays usable until its idle limit, under a token no one should have.

A token in a cookie of its own, as in the tutorial, needs no such step: every sign-in sets a new one. Either way:

- **Cookies:** `Secure`, `HttpOnly`, `SameSite=Lax` (or `Strict`), and the `__Host-` prefix where you can, so a subdomain can't set or overwrite them. The same goes for the device cookie (`StartedSession::$deviceCookie`), which the bridge keeps 400 days as `__Host-yorvoid_device`.
- **On every request,** `Sessions::validate()` the token, and treat `null` as signed out: the session ended, expired, or its principal changed.
- **Signing out** ends the YorvoID session (`Sessions::end()`), then empties your session under a new ID and clears the cookie, so a copy of the old cookie is no use.

## Requests from the browser

- **JSON only, for anything that changes state.** Refuse other bodies with 415. A form on another site can't send JSON, and a script on another site can't without the browser asking your site first, so these endpoints need no CSRF token. The [browser client](../concepts/browser-client.md) sends JSON.
- **Forms that do post HTML** (flow pages, signing out, "This wasn't me") need a CSRF token, or the protection of the flow store below.
- **The client's address** comes from your framework's trusted-proxy handling, not from `X-Forwarded-For` as sent. Trust only your own proxies: otherwise everyone behind the proxy shares one limit per address, or anyone can choose theirs ([throttling](../concepts/throttling.md), [behind a proxy](../concepts/symfony.md#behind-a-proxy)).

## Pages

- **`Cache-Control: no-store`** on every page of a flow: recovery codes must not come back with the Back button after signing out.
- **`Content-Security-Policy: form-action 'self'; frame-ancestors 'self'`** at least, so injected markup can't send a form elsewhere, and other sites can't frame your sign-in.
- **Opening a link changes nothing.** Pages reached from an email (invitations, recovery links, "This wasn't me") show what will happen and act only on a POST: mail scanners open every link.

## Flows

- **A flow belongs to the browser that started it.** The bridge keeps flows in the PHP session (`SessionFlowStore`), so a flow's ID is useless in another browser, and its forms need no CSRF token. A store shared between browsers (a database table, a cache) makes the flow ID a bearer token: bind it to the browser's session if you write one. `InMemoryFlowStore` is for tests.
- **Start only the flows a request may start,** with only the inputs it may give. The bridge starts these from a request, nothing else: `yorvoid.sign_in`, `yorvoid.setup`, `yorvoid.recover_with_code`, `yorvoid.add_passkey`, `yorvoid.invitation` (with the link's `token`), `yorvoid.recover_with_link` (with the link's `token`), `yorvoid.step_up` (with an operation's name). Never pass the request's data into a flow's state wholesale.
- **Hold the session a flow hands over** (`Progress::$signedIn`), with a new session ID as above, and a step-up's renewed token (`Progress::$renewedToken`) the same way.

## Listeners

Register these with your PSR-14 dispatcher and pass it to the services: without them, YorvoID's guarantees across packages don't hold.

| Listener | Of | So that |
|---|---|---|
| `EndSessionsOfInactivePrincipals` | `PrincipalStatusChanging` | Suspending or deleting someone ends their sessions in the same transaction |
| `ForgetCredentialsOfDeletedPrincipals` | `PrincipalStatusChanging` | A deleted principal's credentials, passkeys and recovery codes go with them |
| `RemovePasskeyWithCredential` | `CredentialRemoving` | Removing a sign-in method removes the passkey's key too |
| `SecurityNotifications` | each event in `SecurityNotifications::events()` | People hear about new devices, new and removed sign-in methods, recoveries ([notifications](../concepts/notifications.md)) |
| `SignedOperationNotification` | `SignedOperationRan` | The owner hears about every signed operation |

Notifications need a [mailer](../concepts/mail.md) and YorvoID's [translations](../concepts/translations.md) (`yorvoid/translation`).

## Daily jobs

The bridge's `yorvoid:purge` calls each of these; schedule them yourself:

```php
use YorvoID\Core\Duration;

$sessions->purge(Duration::days(30));
$sessions->purgeDevices(Duration::days(400));
$oneTimeTokens->purge();
$passkeys->purge();
$throttle->purge();
$stepUp->purge();
```

## Checking the setup

`yorvoid:doctor` checks configuration a host can get wrong. Without it, check these yourself before going live:

- HTTPS everywhere, and your domain as the passkeys' relying party ID, with its origin.
- The session cookie's flags, as above; no proxy range that trusts everyone (`0.0.0.0/0`, `::/0`).
- The clock is real, and agrees with the database's.
- Notifications on, with a mailer that delivers.
- Every migration ran (`Migrator::pending()`).
