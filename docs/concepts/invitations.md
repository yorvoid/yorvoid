# Invitations

Invite a person by email: YorvoID creates an invited principal, and a link for you to send. When they open it and accept, they become active, their address is verified, and they choose how they sign in. Invitations are in the `yorvoid/authn` package.

```php
use YorvoID\Authn\Invitation\Invite;

$invitation = $invitations->invite(new Invite($realm, 'ana@example.com'), $origin, $administratorId);

$mailer->send('ana@example.com', 'https://example.com/invitation/'.$invitation->token->secret->reveal());
```

- The link works for 7 days by default (pass `lifetime` to change it), once, and only while the address is still the invitee's.
- `resend()` sends a new link; the earlier one stops working.
- `withdraw()` deletes the invited principal; its link stops working.
- An address that another principal in the realm has throws `IdentifierTaken`.

## Accepting

```php
// The invitation's page
$principal = $invitations->peek($realm, Secret::fromString($fromTheLink)); // null: show "this link doesn't work"

// When they accept
$principal = $invitations->accept($realm, Secret::fromString($fromTheLink), $origin);
```

`accept()` uses the link, activates the principal and verifies its email address, in one transaction. In the same request, start their [session](sessions.md) and let them add a passkey.
