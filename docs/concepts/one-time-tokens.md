# One-time tokens

One-time tokens are behind every link YorvoID sends or hands over: [invitations](invitations.md), [recovery links](recovery-links.md), and your own (a password reset, an email change). They're in the `yorvoid/authn` package.

A token looks like `3f2a9c…e1.Zk9x…Qw`: a 128-bit **selector** that finds it, a dot, and a 256-bit **verifier**. YorvoID keeps only the verifier's SHA-256 hash and compares it in constant time, so a leaked database holds no usable token.

## Issuing a token

```php
use YorvoID\Authn\Token\IssueToken;

$issued = $tokens->issue(
    new IssueToken($realm, $principal->id, 'acme.password_reset', Duration::hours(1), sentTo: 'ana@example.com'),
    $origin,
);

$link = 'https://example.com/reset/'.$issued->secret->reveal(); // shown only now
```

- A token is for one principal and one **purpose**: lowercase letters, digits, dots and underscores, e.g. `acme.password_reset`. YorvoID's own start with `yorvoid.`. A token for one purpose never works for another.
- It expires after its lifetime.
- By default, issuing a token makes the principal's earlier tokens for the purpose stop working: only the newest link works. Pass `newestOnly: false` to keep them.
- Suspended and deleted principals get no tokens, and their tokens stop working. Invited principals get them: that's how invitations work.
- The security log records the purpose and expiry, never the token or the address.
- `OneTimeTokenIssued` follows the commit: a listener can send the email.

### Tokens sent by email

Pass the address you send it to as `sentTo`; it must be the principal's current one. The token then works only while it still is: if the address changes, whoever reads the old inbox can't use the link.

## Using a token

```php
$token = $tokens->consume($realm, 'acme.password_reset', Secret::fromString($fromTheLink), $origin);

if (null === $token) {
    // Wrong, expired, used, replaced, or its principal can't use it: one answer for all.
}
```

`consume()` checks and uses the token in one step: of several requests presenting it at the same moment, exactly one gets it. The token, and the principal's other tokens for the purpose, stop working. A wrong verifier doesn't use up the token.

To show a page before the token is used (e.g. "choose a new password"), use `peek()`, which checks it without using it.

`revoke()` makes a principal's tokens for a purpose stop working. `purge()` deletes expired tokens; run it daily.

## What's guaranteed, and how it's checked

Tokens work at most once, never after they expire or are replaced, and only the newest works. This is specified in a [formal model](../../spec/tokens/one_time_token.qnt) and checked by machine. The tests also replay 100 of the model's executions against the implementation, on every database, step by step. A test with four separate processes presenting the same token at once checks that exactly one gets it.

## Tokens from Quire

The format and hashing are Quire's (`quire.user_token`), so Quire's stored tokens carry over unchanged.
