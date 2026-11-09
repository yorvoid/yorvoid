# Architecture

How YorvoID is put together: what runs where, which package does what, which package may use which, and the rules that keep it that way. For contributors, reviewers, and integrators who want to know what they're adding to their application.

## Context

YorvoID is a set of PHP libraries, not a service. It runs inside your application, in your process, against your database.

```
        people                          operators                       authenticators
  (browser, passkeys)              (console, cron, jobs)        (phones, security keys, password managers)
          │                                  │                                  ▲
          │ HTTPS                            │ yorvoid:* commands               │ WebAuthn, through the browser
          ▼                                  ▼                                  │
 ┌────────────────────────────────────────────────────────────────────────────────────────────┐
 │ your PHP application                                                                       │
 │   your code ──► YorvoID's services: principals, sessions, passkeys, flows, the security log │
 └────────────────────────────────────────────────────────────────────────────────────────────┘
          │ Doctrine DBAL               │ your mailer               │ checkpoints          │ operations to sign
          ▼                             ▼                           ▼                      ▼
   PostgreSQL / SQLite            email delivery          a file, the application's   the intent verifier, a
   (YorvoID's tables next          (Symfony Mailer,        logger, or your own          privileged process with
    to yours)                       or your own Mailer)     sink, outside the database   its own keys (optional)
```

- **People** sign in with passkeys in the browser, through YorvoID's pages or your own, with the [browser client](browser-client.md).
- **Operators** run the console commands: [claiming the installation](setup-codes.md), [recovery links](recovery-links.md), [checks](../how-to/check-the-setup.md), [key rotation](keys.md), [checkpoints](security-log.md).
- **Your application** calls YorvoID's services, directly or through the [Symfony bridge](symfony.md). It decides who may run what: YorvoID records the ability each operation needs, but doesn't enforce it yet ([operations](operations-and-events.md)).
- **The database** holds YorvoID's tables next to yours, under a prefix ([storage](storage.md)). Every record belongs to a [realm](realms.md).
- **Outside the database:** signed [checkpoints](security-log.md) of the security log, so that whoever can write the database can't rewrite its history unnoticed; and, for [signed intents](signed-intents.md), a verifier that trusts nothing your web application says.

## Containers

What runs, and where each package belongs:

```
 browser                         application process (PHP)                        privileged process (PHP)
┌──────────────────────┐        ┌────────────────────────────────────────────┐    ┌─────────────────────────┐
│ @yorvoid/browser     │ HTTPS  │ yorvoid/symfony (or your own integration)  │    │ yorvoid/intent-verifier │
│ passkey ceremonies,  │◄──────►│ yorvoid/flows       yorvoid/intent-signing │───►│ its own keys and state, │
│ autofill, step-up,   │        │ yorvoid/passkeys    yorvoid/notifications  │    │ its own signing pages,  │
│ errors as codes      │        │ yorvoid/authn       yorvoid/mail           │    │ at its own origin       │
└──────────────────────┘        │ yorvoid/throttle    yorvoid/translation    │    └─────────────────────────┘
                                │ yorvoid/core                               │
                                └────────────────────────────────────────────┘
                                      │                         │
                                      ▼                         ▼
                                 the database             checkpoint sinks
```

| Package | What it does |
|---|---|
| `yorvoid/core` | Realms, principals and identifiers, the hash-chained security log and its checkpoints, signing keys, storage and migrations, operations and events. |
| `yorvoid/throttle` | At most so many attempts per period, shared by every server through the database. |
| `yorvoid/authn` | Sessions, one-time tokens, setup and recovery codes, recovery links, invitations, step-up. |
| `yorvoid/passkeys` | WebAuthn registration and sign-in, user verification, passkey management. |
| `yorvoid/flows` | Sign-in, setup, recovery, enrolment and the other journeys as server-side steps. |
| `yorvoid/notifications` | Security emails, "This wasn't me", email address verification. |
| `yorvoid/mail` | Emails from structured content, as plain text and accessible HTML. |
| `yorvoid/translation` | ICU messages from the catalogues packages ship. |
| `yorvoid/intent-signing` | The application's side of signed intents: asking, limits, summaries, the log. |
| `yorvoid/intent-verifier` | The privileged side of signed intents. Stands alone: no framework, no database layer, no other YorvoID package. `Verifier` is its API, over `IntentChecks` (what the browser answers) and `KeyRegistry` (the keys and their changes, replayed against its formal model). |
| `yorvoid/symfony` | The bundle: configuration, firewall authenticators, user provider, console commands, mailer and translator glue. |
| `yorvoid/testing`, `yorvoid/virtual-authenticator` | For your tests: fixtures, a movable clock, a software authenticator. |
| `@yorvoid/browser` | The browser client, on npm. No dependencies. |

Install only what you use: each package requires what it needs, and nothing else ([compatibility](../compatibility.md#versions)).

## Which package may use which

Dependencies point one way, towards `yorvoid/core`, and never in a circle.

| Package | May use |
|---|---|
| `yorvoid/core` | none |
| `yorvoid/intent-verifier` | none |
| `yorvoid/virtual-authenticator` | none |
| `yorvoid/throttle` | core |
| `yorvoid/mail` | core |
| `yorvoid/translation` | core |
| `yorvoid/authn` | core, throttle |
| `yorvoid/flows` | core, authn, throttle |
| `yorvoid/notifications` | core, authn, mail |
| `yorvoid/passkeys` | core, authn, flows, notifications, throttle |
| `yorvoid/intent-signing` | core, authn, intent-verifier, notifications, throttle |
| `yorvoid/symfony` | every package above but `virtual-authenticator` |
| `yorvoid/testing` | core, authn, passkeys, symfony, throttle, virtual-authenticator |

The exact rules are in [`deptrac.yaml`](https://github.com/yorvoid/yorvoid/blob/main/deptrac.yaml), and CI enforces them, together with:

- **each package requires what it uses**, and uses what it requires (`composer dependencies`), since packages are installed one by one;
- **no package uses another package's `@internal` classes**, which may change in any release (a PHPStan rule);
- **nothing marked `@api` names an `@internal` class** in what it exposes (a PHPStan rule);
- **every break of `@api` code is in the changelog**: each package is compared with its last release ([compatibility](../compatibility.md)).

## Design rules

The decisions that shape every package:

- **Realms isolate.** Every record, every query and every log entry belongs to one [realm](realms.md); nothing crosses realms.
- **Every change is an operation, and every operation is recorded.** Commands are classes marked `#[Operation]`, in one [catalogue](operations-and-events.md). Each change records an entry in the [security log](security-log.md) in the same transaction, and dispatches events before (cancellable) and after.
- **The security log is a hash chain per realm,** with signed checkpoints kept outside the database. Appending takes the realm's head row, so changes that must count what's there (the last way to sign in, a new set of recovery codes) take turns.
- **Stores are interfaces.** Each has a DBAL implementation and one in memory, and both pass the same conformance tests ([storage](storage.md)). A failed change undoes what it did in every store.
- **Secrets never leave as plain values.** They're held in `Secret`, compared in constant time, and redacted in dumps and logs ([secrets](secrets.md)); a PHPStan rule refuses variable-time comparisons.
- **The server decides.** Flows are server-side steps; the browser client runs ceremonies and shows results, but every check happens on the server ([flows](flows.md)).
- **The privileged side trusts nothing the web side says.** Signed intents are signed on pages the verifier serves from its own origin, with passkeys of its own ([signed intents](signed-intents.md)).
- **One version for everything.** All packages, Composer and npm, are released together ([compatibility](../compatibility.md)).
