# Principals

A principal is anything that can act or be given authority: a person, a service, a workload, an AI agent, or whoever holds an account-less capability such as a scorekeeper's link. All of them go through the same authorization.

| Kind | What it is |
|------|------------|
| `human` | A person |
| `service` | An application or service acting as itself |
| `workload` | A workload with an identity from its platform, e.g. SPIFFE |
| `agent` | An AI agent acting with authority delegated to it |
| `capability` | Whoever holds a capability link or PIN |

A principal has an ID (a UUIDv7, e.g. `01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c`) and belongs to one [realm](realms.md).

## Profiles stay in your application

YorvoID keeps the security state of a principal: its status, credentials, sessions, grants. Names, avatars, preferences and other profile data belong in your application's own tables, linked by the principal's ID. Your application can use the principal's ID as its user ID.

## Creating principals

```php
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Realm;

$principal = $principals->create(
    new CreatePrincipal(Realm::default(), PrincipalKind::Human),
    new Origin(OriginKind::Console, 'app:install'),
);
```

Creating a principal is one transaction, joining yours if one is open: the principal, its [security log](security-log.md) entry, and the `PrincipalCreating` event, which a listener can cancel to refuse it. `PrincipalCreated` follows once the transaction committed.

A person can have an email address and a username, given when they're created or later:

```php
$principal = $principals->create(
    new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', username: 'ana'),
    $origin,
);
```

If either is taken or invalid, nothing is created.

## Lifecycle

A principal's status changes like this:

```
invited ──→ active ⇄ suspended
   │          │          │
   └──────────┴──────────┴──→ deleted
```

```php
$principals->activate(new ActivatePrincipal($realm, $id), $origin, $actor);  // invited, or suspended
$principals->suspend(new SuspendPrincipal($realm, $id), $origin, $actor);    // can't sign in or act
$principals->delete(new DeletePrincipal($realm, $id), $origin, $actor);
```

- Any other change throws `InvalidStatusChange`: a deleted principal never comes back.
- Each change is one transaction with its log entry. `PrincipalStatusChanging` comes before (a listener can cancel it, or remove a deleted person's profile in the same transaction), `PrincipalStatusChanged` after the commit.
- A change made from an outdated read (someone else changed the principal meanwhile) throws `ConcurrentChange`, and nothing changes. Try again.
- **Deleting** keeps a tombstone (ID, realm, kind, status, times), so the security log's references stay valid, and removes everything else YorvoID holds about the principal: its email address and username can be used again.
- Each principal counts its status changes (`statusVersion`). What was granted at one version, such as a [session](sessions.md), ends with the next change: undoing a suspension doesn't bring back sessions that were open before it.

## Identifiers

Email addresses and usernames identify people when they sign in or are invited. Each is optional, a principal has at most one of each, and each is unique within its realm regardless of case.

| | Rules | Compared |
|---|---|---|
| Email address | `name@domain`, at most 254 characters, no spaces or control characters | In lowercase |
| Username | 1 to 64 letters (a–z), digits, dots, underscores or hyphens; starts and ends with a letter or digit | In lowercase |

Usernames are ASCII only, so that no two usernames look alike (a Cyrillic `а` and a Latin `a`). Display names, in any script, belong in your application's profile.

```php
$principals->setIdentifier(new SetIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.org'), $origin, $actor);
$principals->verifyIdentifier(new VerifyIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.org'), $origin);
$principals->removeIdentifier(new RemoveIdentifier($realm, $id, IdentifierKind::Email), $origin, $actor);

$principal = $principals->findByIdentifier($realm, IdentifierKind::Email, 'ANA@example.org');
```

- **Verified** means the owner showed it's theirs, e.g. by opening a link sent to the address. Only the code that saw them do so marks it; `verifyIdentifier()` checks the address is still the one that was verified. Setting a different address makes it unverified again.
- `IdentifierTaken` says another principal has it. Telling a stranger that tells them the address has an account: flows anyone can reach answer the same either way, and send an email instead.
- The security log records which kind of identifier changed, never its value: the log can't forget, and an email address is personal data that must go when its owner does.

## Guard rules

Some invariants must hold whichever page, command or script makes a change, such as "at least one active owner", or Quire's "the last administrator can't be suspended". Write them as guard rules:

```php
use YorvoID\Core\Principal\GuardRule;
use YorvoID\Core\Principal\PrincipalChange;

final readonly class KeepAnOwner implements GuardRule
{
    public function __construct(private Owners $owners) {}

    public function check(PrincipalChange $change): ?string
    {
        return $this->owners->includes($change->before) && 0 === $this->owners->countActive($change->after->realm)
            ? 'The last active owner can\'t be suspended or deleted.'
            : null;
    }
}

$principals = new Principals(/* … */, guardRules: [new KeepAnOwner($owners)]);
```

A rule runs inside the change's transaction, after the change is written, and sees the state as it will be if the change commits. Changes in a realm wait for each other while rules run, so two administrators suspending the last two owners at the same moment can't both succeed. A rule that refuses throws `GuardRuleViolated` with its reason, and nothing changes.

