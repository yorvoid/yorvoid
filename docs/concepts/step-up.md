# Step-up

Some operations need more than a signed-in session: removing a passkey, replacing recovery codes, deleting a site. Before them, YorvoID asks for a **recent proof of who the person is**, and the session that can't show one gets a refusal that says what to prove. Proving it again is a **step-up**. It's in the `yorvoid/authn` package; the [passkeys](passkeys.md#stepping-up) package provides the proof.

```php
use YorvoID\Authn\StepUp\AuthenticationRequirement;

$stepUp->require($session, new AuthenticationRequirement(Duration::minutes(5), operation: 'acme.site.remove'));
```

## Requirements

An `AuthenticationRequirement` says what the operation needs:

| | Default | |
|---|---|---|
| `within` | — | How recent the proof must be, e.g. 5 minutes. |
| `multiFactor` | `false` | Two factors, e.g. a passkey with user verification (AAL2). |
| `phishingResistant` | `false` | A proof a look-alike site can't relay, e.g. a passkey. |
| `operation` | `null` | The operation's name, e.g. `acme.site.remove`, as in its `#[Operation]`. |
| `heldAfterRecovery` | `false` | Whether it waits out the [cool-down after a recovery](#the-cool-down-after-a-recovery). |

A proof from **signing in** counts for any operation. A proof from a **step-up** counts only for the operation it was for: confirming one removal doesn't open a window for every other sensitive operation ("sudo mode", limited to what it was granted for).

`require()` reads the session as it is now, so a step-up in another request counts at once. An ended or expired session never meets a requirement.

The [credential registry](credentials.md) asks for a proof within 5 minutes before adding (`yorvoid.credential.add`) or removing (`yorvoid.credential.remove`) a credential, and [recovery codes](recovery-codes.md) before replacing a set.

## Stepping up

When a requirement isn't met, `StepUpRequired` carries it as the **remedy**: hand it to the step-up, then retry the operation.

```php
try {
    $sites->remove($site, $session);
} catch (StepUpRequired $refusal) {
    $ceremony = $passkeys->beginStepUp($session, $refusal->requirement);
    // The browser calls navigator.credentials.get() with $ceremony->options.
}

// When the browser answers:
$token = $passkeys->finishStepUp($session, $ceremonyId, $responseFromTheBrowser, $origin);
// The session has a new token: replace the old one in its cookie.
```

The proof is added to the session's [authentication context](sessions.md), and the session gets a new token: the old one stops working (SES-002). `SessionSteppedUp` follows the commit. A session's context keeps only the latest of identical proofs, so a long session doesn't grow with every step-up.

## The cool-down after a recovery

Someone who recovers an account that isn't theirs (with stolen recovery codes, or a recovery link from a helpdesk they deceived) shouldn't be able to lock the owner out before the owner notices. With a cool-down configured, a recovery holds the operations that ask for it for that long:

```php
$stepUp = new StepUp($sessions, $coolDownStore, $log, coolDown: Duration::hours(24));
```

- It's off unless configured.
- [Recovery codes](recovery-codes.md) and [recovery links](recovery-links.md) start it; the security log records it.
- Removing a credential and replacing recovery codes wait it out. Adding one doesn't: someone who lost their passkeys recovers to add a new one.
- A held operation throws `HeldAfterRecovery` with the moment it's possible again. Stepping up doesn't help; tell the person when.
- `coolDownEndsAt()` tells whether a principal is cooling down, e.g. to show it on their security page. Purge ended cool-downs daily with `$stepUp->purge()`.
