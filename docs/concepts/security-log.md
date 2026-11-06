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

Because every change to a realm records an entry, the log's lock is also how changes that depend on what the realm holds take turns. `lock()` takes it without recording, until the transaction ends: lock first, then read, then change. A setup code does it before checking that the realm has no active principal, so an invitation accepted at the same moment waits for the claim, or the claim waits and sees it.

```php
$transactions->run(function () use ($log, $realm): void {
    $log->lock($realm);
    // Read what the change depends on, then change it.
});
```

A `SecurityLogStore` of your own implements `lock()` as an append locks: on a database, the realm's row that appends update, locked until the transaction ends.

## What the chain proves, and what it doesn't

Verification detects any change to entries that left the rest of the chain in place, and entries removed from the end while the realm's head, which records the last one, still names them.

Someone who can write to the database can, however, change the head too: cut entries off the end, or rewrite the whole chain from the point they changed, and the chain alone still verifies. [Checkpoints](#checkpoints) kept outside the database close that gap, up to the last checkpoint. Take the privileges to rewrite the log from the application's database user too, if you can.

Recording needs only `INSERT` and `SELECT` on `yorvoid_security_log`, and `INSERT`, `SELECT` and `UPDATE` on `yorvoid_security_log_head`. On PostgreSQL, run migrations as a user that owns YorvoID's tables, and grant the application's user only those; `yorvoid:doctor` warns while it can update, delete or truncate the log, or delete or truncate its head. SQLite has no privileges: whoever can write its file can change anything.

Hashes name their algorithm (`sha256` today). Verification refuses any algorithm YorvoID doesn't accept, so an entry can't be "re-hashed" with a weaker one.

## Checkpoints

A **checkpoint** records where a realm's log stood: its last entry's number and hash, and when, signed with the [key ring](keys.md)'s active key. It's kept outside the database, where the application's database user can't change it. Verification against checkpoints finds what the chain alone can't:

- entries cut off from the end, with the head moved back: the checkpointed entry is missing;
- a history rewritten up to a checkpoint, with every hash recomputed: the checkpointed entry's hash differs.

What's recorded after the last checkpoint has only the chain's protection until the next one, so take them often.

```php
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\SharedKeyRings;
use YorvoID\Core\Log\Checkpoint\Checkpoints;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Log\Checkpoint\LoggerCheckpoints;
use YorvoID\Core\Realm;

$file = new FileCheckpoints('/mnt/audit/yorvoid-checkpoints.jsonl');  // another disk or host
$checkpoints = new Checkpoints($logStore, $log, new SharedKeyRings(KeyRing::fromFile('/run/secrets/yorvoid-keys.json')), [$file, new LoggerCheckpoints($logger)]);

$checkpoints->take(Realm::default());  // from cron, every few minutes

$verification = $checkpoints->verify(Realm::default(), $file->read(Realm::default()));
```

- `FileCheckpoints` appends one JSON line per checkpoint, and reads them back. Put the file on another disk or host, ideally append-only, and keep it with the database's backups.
- `LoggerCheckpoints` gives each checkpoint to the application's logger (`yorvoid.log.checkpoint`, at notice), for a pipeline that ships logs elsewhere. To verify, read them back from there into a `CheckpointSource`.
- A checkpoint the ring's keys didn't sign, or a line of the file that isn't one, fails verification. Keep the keys a checkpoint was signed with in the ring, verify-only, as long as you keep the checkpoint.

In Symfony, set `yorvoid.log.checkpoint_file` (or `checkpoint_logger`) next to `yorvoid.keys.file`, and run `yorvoid:log:checkpoint` from cron; `yorvoid:log:verify` checks the chain and the checkpoints. `yorvoid:doctor` warns outside development while there are none, or the newest is more than a day old.
