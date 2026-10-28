# Recovery codes

Recovery codes get someone back in when they lose their passkeys. They save them when they set up their account (printed, or in a password manager), and each one works once. They're in the `yorvoid/authn` package.

```
7KQ2-M9XH-E4TB-0WSA-3NPR-HC5D
```

## Generating codes

```php
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;

$codes = $recoveryCodes->generate(new GenerateRecoveryCodes($realm), $session, $origin);

foreach ($codes as $code) {
    echo $code->reveal(), "\n";   // shown only now
}
```

- A set has 10 codes by default. Each is 120 random bits, written as 24 characters of Crockford's base 32 in groups of four. Only their SHA-256 hashes are kept: above 112 bits, a fast hash is enough (OWASP ASVS 6.5.2), since no one can try them all.
- The set is one entry in the [credential registry](credentials.md), "Recovery codes". It doesn't count as a way to sign in, so it never stands in for the last passkey.
- Generating a new set replaces the old one: the old codes stop working.
- The first set needs a recent proof of identity, as adding any credential does. A new set needs one too, for `yorvoid.recovery_codes.generate`, and waits out the [cool-down after a recovery](step-up.md#the-cool-down-after-a-recovery): it would make the owner's saved codes useless.
- `RecoveryCodesGenerated` follows the commit, for a notification.

`remaining($realm, $principalId)` says how many are left; suggest a new set when few are.

## Using a code

Ask for the person's email address (or username) and a code. Find the principal, then:

```php
$started = $recoveryCodes->redeem($realm, $principalId, Secret::fromString($typed), $origin, device: 'Firefox on Linux', address: $request->getClientIp());
```

- Case, hyphens and spaces don't matter, and O, I and L count as 0, 1 and 1.
- A code works once. Of several requests presenting it at the same moment, one gets it.
- Using one **ends all of the principal's sessions**, since whoever else had one may be why they lost access, starts the [cool-down](step-up.md#the-cool-down-after-a-recovery), and starts a new session. Then let them add a passkey.
- `RecoveryCodeUsed` follows the commit, with how many codes are left: tell the principal on every verified channel. If it wasn't them, they need to know now.
- Attempts are [throttled](throttling.md) per account and address, per account and per address: pass `address:`. Too many throw `Throttled`, with when to try again; a code that works forgets that account's attempts from that address.
- A wrong code returns `null`, and the attempt is recorded. Answer the same when there's no such principal, so the form doesn't tell which accounts exist.

A recovery code is one factor and can be phished: a session started with one meets a requirement for any proof, but not one for two factors or a phishing-resistant proof.

## Configuration

| Setting | Default | |
|---|---|---|
| `count` | 10 | Codes in a set, between 1 and 100. |
