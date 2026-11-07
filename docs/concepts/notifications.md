# Notifications

People should hear about every change to how they sign in, and about sign-ins from new devices, so that someone else's change doesn't go unnoticed. YorvoID sends **security notifications** people can act on (UX-016): each says what happened, when and from where, and offers **"This wasn't me"**. They're in the `yorvoid/notifications` package, with the link that verifies an email address.

| Event | Email |
|---|---|
| `NewDeviceSignedIn` | A sign-in from a device the person hasn't used before ([devices](sessions.md#recognising-devices)) |
| `CredentialAdded` | A sign-in method was added, e.g. a passkey |
| `CredentialRemoved` | A sign-in method was removed |
| `RecoveryCodeUsed` | A recovery code was used, with how many are left |
| `RecoveryCodesGenerated` | New recovery codes replaced the old ones |
| `IdentifierChanged` | The email address was changed or removed: to the **old** address |

They go to the principal's email address when it's verified, in the principal's language, and can't be turned off.

Whoever signed in or added a passkey may be the attacker the email warns about, so none of their words reach it as written. The device is the browser and system from short lists ("Chrome on Windows", else "Unknown"), never the User-Agent header; a sign-in method's name is on one line, without reading-direction tricks, and in quotes.

## Configuration

```php
use YorvoID\Notifications\NotificationSettings;
use YorvoID\Notifications\SecurityNotifications;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

$notifications = new SecurityNotifications($principals, $oneTimeTokens, $mailer, $translator, new NotificationSettings(
    applicationName: 'Acme',
    notMeUrl: 'https://example.com/security/not-me?token={token}&credential={credential}',
    localeOf: fn (Realm $realm, PrincipalId $id): string => $yourProfiles->languageOf($id),
));

foreach (SecurityNotifications::events() as $event => $method) {
    $yourDispatcher->addListener($event, [$notifications, $method]);
}
```

- `$mailer` delivers the emails ([mail](mail.md)); `$translator` is any Symfony `TranslatorInterface` with YorvoID's [catalogues](translations.md).
- `notMeUrl` is your page for "This wasn't me", with `{token}` and `{credential}` where they go: an `https://` address (`http://` only for `localhost`), with the token in its path or query, never in the host, and no user name or password before the host.
- The events come after the commit; the listeners issue a one-time token each, so run your work through `$transactions->run()` ([storage](storage.md#transactions)).

## "This wasn't me"

Each email's link holds a one-time token, bound to the email address and valid for 7 days. Opening the link only shows your page: mail scanners and link previews open every link in an email, so act only when the person presses a button. The page asks "Wasn't this you?" with a form that posts the token and the credential back, with a CSRF token, and the POST confirms it:

```php
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Secret;
use YorvoID\Notifications\ReportNotMe;

// GET: show the question and a form; POST, after checking the CSRF token:
$principalId = $operations->run(new ReportNotMe($realm, Secret::fromString($request->request->getString('token')), $request->request->get('credential')), new Anonymous(), $origin);
```

- It signs everyone out of the account (`SessionEndReason::Reported`).
- If the email was about a new sign-in method, that method is disabled, so whoever added it can't sign in with it.
- It's recorded, and `NotMeReported` follows the commit.
- Then lead the person to recovery: their recovery codes, or a recovery link from an operator. `null` means the link doesn't work (used, expired, or altered).

## Verifying the email address

Notifications go only to a verified address, so an address nobody confirmed hears nothing: the owner who claimed the installation, or someone who just changed theirs. `EmailVerification` sends a link to confirm it:

```php
use YorvoID\Notifications\EmailVerification;
use YorvoID\Core\Principal\Event\IdentifierChanged;

$verification = new EmailVerification($principals, $oneTimeTokens, $mailer, $translator, $settings, $transactions,
    fn (string $token): string => 'https://example.com/verify-email?token='.rawurlencode($token));

$yourDispatcher->addListener(IdentifierChanged::class, [$verification, 'identifierChanged']);
```

- The listener sends a link to every new address that isn't verified. `send($realm, $principalId, $origin)` sends one yourself, e.g. if the person asks again.
- The link works once, for a day, only while the address is still the principal's, and a newer link replaces an older one.
- Your page shows what `peek()` gives and asks the person to confirm; on POST, `confirm()` verifies the address. As with "This wasn't me", opening the link must change nothing.
- The Symfony bridge does all of this when notifications are on: the link leads to the `yorvoid.verify_email` [flow](flows.md), and claiming a fresh installation sends the owner one.
