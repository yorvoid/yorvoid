# Security log

YorvoID records every security-relevant event of a realm in its security log: sign-ins and failed attempts, changes to principals, credentials and authority, decisions about protected operations. The log is append-only and **hash-chained**: each entry contains the hash of the entry before it, so changing, removing, inserting or reordering an entry breaks the chain, and verification says where.

```php
use YorvoID\Core\Realm;

$log->verify(Realm::default());   // Verification: isIntact(), entriesChecked, brokenAt, problem
```

## Size

The log grows with every recorded event, a few hundred bytes each, and nothing prunes it: an entry can't be removed without breaking the chain after it. `verify()` reads it in batches of 1,000 entries, so checking a long log takes time in proportion to its length, not memory: run it from a scheduled job rather than in a request. Keeping it to a size, by archiving old entries with their hashes, comes with external checkpoints.

## Entries

Each entry has:

| Field | What it is |
|-------|------------|
| sequence | 1, 2, 3, … per realm, without gaps |
| id, recorded at | A UUIDv7, and the time to the second |
| action | The operation, e.g. `yorvoid.principal.create` |
| outcome | `success`, `denied` or `failed` |
| origin | Web, API, console, job or system; what ran; the correlation ID |
| actor | The principal who acted, if one did |
| subject | What it was about, e.g. `principal` and its ID |
| details | Facts that help reconstruct what happened |
| algorithm, previous hash, hash | The chain |

Failed attempts are recorded too, with outcome `failed` and nothing typed in the details: a setup code that didn't work (`yorvoid.setup.claim`), a token or link that didn't (`yorvoid.token.consume`, with its purpose), a recovery code (`yorvoid.recovery_code.use`). A limit that refuses attempts is recorded once per window, not once per attempt (`yorvoid.attempt.throttled`, `denied`, with the form and a hash of what was counted).

Details hold facts, never secrets, tokens, passwords or copies of personal data: point to records instead of copying them. They're strings, integers, booleans, null, and lists or maps of those, up to 16 KB. Numbers with decimals are written as strings, because their text form isn't stable enough to hash.

## How entries are written

An entry is written in the same transaction as the change it describes, so the log holds exactly what was committed, no more and no less. Appends to a realm's log are serialized: two processes can never give two entries the same number. After commit, each entry is dispatched as a `SecurityLogEntryRecorded` event, for your own audit log or a SIEM.

## What the chain proves, and what it doesn't

Verification detects any change to entries that left the rest of the chain in place, and entries removed from the end while the realm's head, which records the last one, still names them.

Someone who can write to the database can, however, change the head too: cut entries off the end, or rewrite the whole chain from the point they changed, and verification still passes. Checkpoints kept outside the database close that gap; they arrive by 1.0. Until then, the chain protects against accidental and partial tampering, not against an attacker with write access to the database: take those privileges from the application's database user if you can.

Recording needs only `INSERT` and `SELECT` on `yorvoid_security_log`, and `INSERT`, `SELECT` and `UPDATE` on `yorvoid_security_log_head`. On PostgreSQL, run migrations as a user that owns YorvoID's tables, and grant the application's user only those; `yorvoid:doctor` warns while it can update, delete or truncate the log, or delete or truncate its head. SQLite has no privileges: whoever can write its file can change anything.

Hashes name their algorithm (`sha256` today). Verification refuses any algorithm YorvoID doesn't accept, so an entry can't be "re-hashed" with a weaker one.
