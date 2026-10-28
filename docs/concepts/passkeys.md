# Passkeys

Passkeys are the main way people sign in to YorvoID's applications: phishing-resistant, nothing to remember, and on most devices already. They're in the `yorvoid/passkeys` package, which uses [web-auth/webauthn-lib](https://github.com/web-auth/webauthn-framework) for WebAuthn's checks.

## Configuration

```php
use YorvoID\Passkeys\PasskeyPolicy;

$policy = new PasskeyPolicy(
    relyingPartyId: 'example.com',          // passkeys belong to this domain and its subdomains
    origins: ['https://example.com'],       // where the pages that use them are
);
```

| Setting | Default | |
|---|---|---|
| `userVerification` | `Required` | The device checks it's its owner (PIN, fingerprint, face). See below. |
| `clonePolicy` | `RefuseSignIn` | What a suspected copy does. See below. |
| `timeout` | 5 minutes | How long a registration or sign-in may take. |

Origins are `https://` and a host at or under the relying party ID, with an optional port; `http://localhost` only for development.

## Registering a passkey

From a session that proved who it is in the last 5 minutes (see [credentials](credentials.md)), e.g. right after accepting an [invitation](invitations.md) or claiming a realm with a [setup code](setup-codes.md):

```php
$ceremony = $passkeys->beginRegistration($session);
// Send $ceremony->id and $ceremony->options to the browser, which calls navigator.credentials.create().

$credential = $passkeys->finishRegistration($session, $ceremonyId, $responseFromTheBrowser, 'MacBook', $origin);
```

The passkey is added to the [credential registry](credentials.md) with its label. The options are discoverable credentials ("resident keys"), so sign-in needs no username; ES256, EdDSA and RS256 keys; no attestation; and the principal's existing passkeys excluded, so a device isn't asked to make a second one for the same account.

Each principal has one WebAuthn user handle: 32 random bytes, stored on their authenticators, so it says nothing about them.

## Signing in

```php
$ceremony = $passkeys->beginSignIn($realm);
// The browser calls navigator.credentials.get(), also as autofill (mediation: "conditional").

$started = $passkeys->finishSignIn($realm, $ceremonyId, $responseFromTheBrowser, $origin, device: 'Firefox on Linux', address: $request->getClientIp());
```

The browser offers the person's passkeys for the site; no username first. Success starts a [session](sessions.md) whose authentication context records a passkey: phishing-resistant, and two factors if the device verified its owner.

Sign-ins from one address are [throttled](throttling.md): pass `address:`.

A refused attempt throws `PasskeyRefused`. Show its message: it's the same for every reason a guesser could learn from (unknown passkey, wrong signature, disabled passkey, suspended account). `$refusal` says the reason, for your logs. Failed sign-ins with a known passkey are recorded in the security log.

## Stepping up

When an operation needs a recent proof of identity, `StepUpRequired` carries the requirement (see [step-up](step-up.md)). The person confirms it's them with one of their passkeys:

```php
$ceremony = $passkeys->beginStepUp($session, $refusal->requirement);
// The browser calls navigator.credentials.get(); it offers only this person's passkeys.

$token = $passkeys->finishStepUp($session, $ceremonyId, $responseFromTheBrowser, $origin);
```

- The ceremony belongs to the session that began it, and the answer must come from one of its principal's active passkeys.
- The proof counts for the requirement's operation only, and the session gets a new token: replace the old one in its cookie.
- A requirement of two factors asks the device for user verification, whatever the policy.
- A principal without an active passkey gets `PasskeyRefused` with `Refusal::NoPasskey`: offer another way, e.g. a recovery code.

## User verification

With `UserVerification::Required` (the default), every registration and sign-in must come from a device that checked its owner. YorvoID checks the authenticator's flag itself, besides the library, whatever the options said: the options are YorvoID's own and kept server-side, so the browser can't lower the requirement. (webauthn-lib 5.3.0 let options lower it, GHSA-h4fw-6r7f-w494; a test keeps that from coming back.)

With `UserVerification::Preferred`, a passkey used without it counts as **one factor** (NIST SP 800-63B-4, appendix B), and the session records that.

## Ceremonies

Each registration and sign-in is a ceremony kept in the database: any server can finish what another began. A ceremony works once, expires after the timeout, and its challenge is 256 random bits. A registration or a step-up can only be finished by the session that began it. Purge expired ceremonies daily with `$passkeys->purge()`.

## Suspected copies

Hardware keys count their signatures. If a sign-in reports a count that didn't go up, the key may have been copied:

| `clonePolicy` | |
|---|---|
| `RefuseSignIn` (default) | Refuse this sign-in, and record it |
| `DisablePasskey` | Also disable the passkey: its owner signs in another way and removes it |
| `Allow` | Let it through, and record it |

Passkeys that sync between devices always report 0 and are never suspected. Whether a passkey syncs (its backup flags) is recorded, and isn't a reason to refuse it.

## Removing a passkey

Passkeys are removed through the [credential registry](credentials.md). Register `RemovePasskeyWithCredential` as a listener of `CredentialRemoving`, so the public key goes in the same transaction. (Without it, a removed passkey can't sign in anyway: sign-in needs its registry entry.)
