# Security log

YorvoID records every security-relevant event of a realm in its security log: sign-ins and failed attempts, changes to principals, credentials and authority, decisions about protected operations. The log is append-only and **hash-chained**: each entry contains the hash of the entry before it, so changing, removing, inserting or reordering an entry breaks the chain, and verification says where.

```php
$log->verify(Realm::default());   // Verification: isIntact(), entriesChecked, brokenAt, problem
```

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

Details hold facts, never secrets, tokens, passwords or copies of personal data: point to records instead of copying them. They're strings, integers, booleans, null, and lists or maps of those, up to 16 KB. Numbers with decimals are written as strings, because their text form isn't stable enough to hash.

## How entries are written

An entry is written in the same transaction as the change it describes, so the log holds exactly what was committed, no more and no less. Appends to a realm's log are serialized: two processes can never give two entries the same number. After commit, each entry is dispatched as a `SecurityLogEntryRecorded` event, for your own audit log or a SIEM.

## What the chain proves, and what it doesn't

Verification detects any change to entries that left the rest of the chain in place, and entries removed from the end (the realm's head records the last one).

Someone who can write to the database can, however, rewrite the whole chain from the point they changed. Signed checkpoints kept outside the database close that gap; they arrive in a later release. Until then, the chain protects against accidental and partial tampering, not against an attacker with full write access to the database.

Hashes name their algorithm (`sha256` today). Verification refuses any algorithm YorvoID doesn't accept, so an entry can't be "re-hashed" with a weaker one.
