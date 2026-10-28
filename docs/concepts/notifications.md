# Notifications

People should hear about every change to how they sign in, and about sign-ins from new devices, so that someone else's change doesn't go unnoticed. YorvoID sends **security notifications** people can act on (UX-016): each says what happened, when and from where, and offers **"This wasn't me"**. They're in the `yorvoid/authn` package.

| Event | Email |
|---|---|
| `NewDeviceSignedIn` | A sign-in from a device the person hasn't used before ([devices](sessions.md#recognising-devices)) |
| `CredentialAdded` | A sign-in method was added, e.g. a passkey |
| `CredentialRemoved` | A sign-in method was removed |
| `RecoveryCodeUsed` | A recovery code was used, with how many are left |
| `RecoveryCodesGenerated` | New recovery codes replaced the old ones |

They go to the principal's email address when it's verified, in the principal's language, and can't be turned off.

## Configuration

```php
use YorvoID\Authn\Notification\NotificationSettings;
use YorvoID\Authn\Notification\SecurityNotifications;

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
- `notMeUrl` is your page for "This wasn't me", with `{token}` and `{credential}` where they go.
- The events come after the commit; the listeners issue a one-time token each, so run your work through `$transactions->run()` ([storage](storage.md#transactions)).

## "This wasn't me"

Each email's link holds a one-time token, bound to the email address and valid for 7 days. Your page confirms it:

```php
$principalId = $notMe->confirm($realm, Secret::fromString($request->query->get('token')), $request->query->get('credential'), $origin);
```

- It signs everyone out of the account (`SessionEndReason::Reported`).
- If the email was about a new sign-in method, that method is disabled, so whoever added it can't sign in with it.
- It's recorded, and `NotMeReported` follows the commit.
- Then lead the person to recovery: their recovery codes, or a recovery link from an operator. `null` means the link doesn't work (used, expired, or altered).
