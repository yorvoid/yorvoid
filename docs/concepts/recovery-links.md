# Recovery links

When someone loses access (their passkeys are gone, or the only owner is locked out), an operator with access to the server can give them a **recovery link**. It's what Quire Server's `owner:recover` does. Recovery links are in the `yorvoid/authn` package.

```php
use YorvoID\Authn\Recovery\IssueRecoveryLink;

$link = $recoveryLinks->issue(new IssueRecoveryLink($realm, $principalId), new Origin(OriginKind::Console, 'owner:recover'));

$output->writeln('https://example.com/recover/'.$link->secret->reveal());
```

- Issue them only from where the operator is already trusted, such as the server's command line, never from a web page. The operation needs the ability `yorvoid.principals.recover`.
- A link works for 15 minutes by default, once, and only for an active principal.
- It isn't sent by email: the operator hands it over.

## Using a link

```php
$started = $recoveryLinks->redeem($realm, Secret::fromString($fromTheLink), $origin, device: 'Firefox on Linux', address: $request->getClientIp());
```

Using the link **ends all of the principal's sessions** (whoever else had one may be why they lost access), starts the [cool-down after a recovery](step-up.md#the-cool-down-after-a-recovery) if one is configured, and starts a new session. Then let them add a passkey.
