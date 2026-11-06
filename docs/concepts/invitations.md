# Invitations

Invite a person by email: YorvoID creates an invited principal, and a link for you to send. When they open it and accept, they become active, their address is verified, and they choose how they sign in. Invitations are in the `yorvoid/authn` package.

```php
use YorvoID\Authn\Invitation\Invite;

$invitation = $operations->run(new Invite($realm, 'ana@example.com'), $administratorsSession, $origin);

$mailer->send('ana@example.com', 'https://example.com/invitation/'.$invitation->token->secret->reveal());
```

- The link works for 7 days by default (pass `lifetime` to change it), once, and only while the address is still the invitee's.
- `ResendInvitation` sends a new link; the earlier one stops working.
- `WithdrawInvitation` deletes the invited principal; its link stops working.
- Each needs the ability `yorvoid.principals.manage`, or the host's operator.
- An address that another principal in the realm has throws `IdentifierTaken`.

## Accepting

```php
use YorvoID\Authn\Invitation\AcceptInvitation;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Secret;

// The invitation's page
$principal = $invitations->peek($realm, Secret::fromString($fromTheLink)); // null: show "this link doesn't work"

// When they accept
$started = $operations->run(new AcceptInvitation($realm, Secret::fromString($fromTheLink)), new Anonymous(), $origin);
```

Accepting uses the link, activates the principal, verifies its email address and starts their [session](sessions.md), in one transaction; null if the link doesn't work. In the same request, let them add a passkey.
