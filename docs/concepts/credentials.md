# Credentials

Every way a principal signs in or recovers access is a **credential** in one registry, whichever package keeps its secret part: passkeys, passwords, authenticator apps, recovery codes. The registry holds what people see under "your sign-in methods": the kind, a label, when it was added and last used, and whether it's active. It's in the `yorvoid/authn` package.

```php
foreach ($credentials->of($realm, $principalId) as $credential) {
    echo $credential->label, ' – ', $credential->kind->value, ' – last used ', $credential->lastUsedAt?->format('Y-m-d') ?? 'never';
}
```

## Changing how someone signs in

People change their own credentials, from their session:

```php
$credentials->rename(new RenameCredential($realm, $credentialId, 'Work laptop'), $session, $origin);
$credentials->remove(new RemoveCredential($realm, $credentialId, endOtherSessions: true), $session, $origin);
```

Packages add credentials as part of their own flow, e.g. [registering a passkey](passkeys.md).

- **Adding or removing needs a recent proof of identity:** the session must have signed in, or [stepped up](step-up.md) for the change, within the last 5 minutes (configurable). Otherwise `StepUpRequired` names what to prove. A session taken over hours ago can't add the attacker's passkey or remove the owner's. Packages call `requireStepUp($session, 'yorvoid.credential.add')` before starting a ceremony.
- **Removing waits out the [cool-down after a recovery](step-up.md#the-cool-down-after-a-recovery)**, if one is configured (`HeldAfterRecovery`). Adding doesn't.
- **The last way to sign in can't be removed** (`LastCredential`). Passkeys and passwords sign in; recovery codes and authenticator apps don't count, and neither do disabled credentials.
- Removing can **end the principal's other sessions** (`endOtherSessions`), e.g. because the passkey was on a lost phone. The session that asked stays.
- Labels are kept to 64 characters; an empty one becomes the kind's name ("Passkey").
- `CredentialAdded` follows the commit: tell the owner on every verified channel that a sign-in method was added. `CredentialRemoving` comes inside the transaction, for the package that keeps the secret part.
- The security log records the kind, never the label: people write their names into labels.

## Disabled credentials

`disable()` makes a credential stop working without removing it, e.g. a passkey suspected of having been copied. Its owner sees it as disabled, and removes it.
