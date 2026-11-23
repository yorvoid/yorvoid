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
| sequence | 1, 2, 3, … per realm, without gaps, in the order the changes committed |
| id, recorded at | A UUIDv7, and the time to the second, when the change was recorded: a long transaction's entry can carry an earlier time than entries before it in the chain |
| action | The operation, e.g. `yorvoid.principal.create` |
| outcome | `success`, `denied` or `failed` |
| origin | Web, API, console, job or system; what ran; the correlation ID |
| actor | The principal who acted, if one did |
| subject | What it was about, e.g. `principal` and its ID |
| details | Facts that help reconstruct what happened |
| algorithm, previous hash, hash | The chain |

The chain holds what changed. A refused operation and a failed attempt change nothing, so they're [decision records](#the-decision-log) instead, kept outside the caller's transaction and without the realm's lock: a setup code that didn't work (`yorvoid.setup.claim`), a token or link that didn't (`yorvoid.token.consume`, with its purpose), a recovery code (`yorvoid.recovery_code.use`), a passkey refused, and a limit that refuses attempts, once per window (`yorvoid.attempt.throttled`, with the form and a hash of what was counted). Nothing typed is kept. Without a decision log (`SecurityLog` given no `attempts:`), they're entries of the chain, with outcome `failed` or `denied`, as before 0.5.

Details hold facts, never secrets, tokens, passwords or copies of personal data: point to records instead of copying them. They're strings, integers, booleans, null, and lists or maps of those, up to 16 KB. Numbers with decimals are written as strings, because their text form isn't stable enough to hash.

## How entries are written

An entry is written in the same transaction as the change it describes, so the log holds exactly what was committed, no more and no less. Appends to a realm's log are serialized: two processes can never give two entries the same number. After commit, each entry is dispatched as a `SecurityLogEntryRecorded` event, for your own audit log or a SIEM.

Changes that depend on what the realm holds take turns through the realm's lock, not the log's: `$transactions->lockRealm($realm)`, first in the transaction, then read, then change. A setup code takes it before checking that the realm has no active principal, so an invitation accepted at the same moment waits for the claim, or the claim waits and sees it. The log's own lock is taken only at the commit, when the buffered entries are appended, so it orders appends and nothing else. [Storage](storage.md) gives the order of every lock.

```php
$transactions->run(function () use ($transactions, $realm): void {
    $transactions->lockRealm($realm);
    // Read what the change depends on, then change it.
});
```

## What the chain proves, and what it doesn't

Verification detects any change to entries that left the rest of the chain in place, and entries removed from the end while the realm's head, which records the last one, still names them.

Someone who can write to the database can, however, change the head too: cut entries off the end, or rewrite the whole chain from the point they changed, and the chain alone still verifies. [Checkpoints](#checkpoints) kept outside the database close that gap, up to the last checkpoint. Take the privileges to rewrite the log from the application's database user too, if you can.

Decision records, refusals and failed attempts among them, have no entry of their own. Each `yorvoid:log:checkpoint` first appends a **digest** of those written since the last one (`yorvoid.decision.digest`: how many, and a hash over each one's ID and hash), and the checkpoint then covers it. `yorvoid:log:verify --decisions` recomputes each digest from the records, and names those changed or missing since; a window since pruned is kept by its digest only. What's younger than the last digest isn't covered yet: someone who can write the database can delete a refusal of the last minutes, up to the checkpoint interval plus `yorvoid.decision_log.digest_grace` (a minute), and nothing notices. Records written inside a transaction of yours, an operation's permit among them, aren't digested: the entries of the changes they allowed cite them, hash included.

Recording needs only `INSERT` and `SELECT` on `yorvoid_security_log`, and `INSERT`, `SELECT` and `UPDATE` on `yorvoid_security_log_head`. On PostgreSQL, run migrations as a user that owns YorvoID's tables, and grant the application's user only those; `yorvoid:doctor` warns while it can update, delete or truncate the log, or delete or truncate its head. SQLite has no privileges: whoever can write its file can change anything.

Hashes name their algorithm (`sha256` today). Verification refuses any algorithm YorvoID doesn't accept, so an entry can't be "re-hashed" with a weaker one.

## Checkpoints

A **checkpoint** records where a realm's log stood: its last entry's number and hash, and when, signed with the active key of the realm's [key ring](keys.md#a-ring-for-each-realm). It's kept outside the database, where the application's database user can't change it. Verification against checkpoints finds what the chain alone can't:

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
- A checkpoint that can't be signed or kept raises the [audit alert](#when-a-record-cant-be-written), and `yorvoid:log:checkpoint` fails: a missing checkpoint is a lost audit record.
- An **anchor** is a checkpoint signed only once the log verifies against every checkpoint before it (`Checkpoints::anchor()`, `yorvoid:log:anchor`). Moving the keys from Ed25519 to ES256 anchors each realm, so that under a FIPS provider without EdDSA the anchor covers what the Ed25519 checkpoints did ([moving to ES256](keys.md#moving-to-es256)).

In Symfony, set `yorvoid.log.checkpoint_file` (or `checkpoint_logger`) next to `yorvoid.keys.file` (or `keys.directory`), and run `yorvoid:log:checkpoint` from cron; `yorvoid:log:verify` checks the chain and the checkpoints. `yorvoid:doctor` warns outside development while there are none, or the newest is more than a day old.

## The decision log

Decisions are recorded apart from the security log, in `yorvoid_authorization_decision`. Each record holds the decision's ID, time, realm, the operation or ability, the acting principal, the resource, the verdict and its basis, the summary reason, why it couldn't decide, the remedies' names, each element's name and truth value (never their texts, which can hold data), what it read and the origin. It's hashed over a versioned canonical form.

What it read is its **read set**: each source's version (`policy`, `relations`) as read just before its elements judged, which they read at, and the sources that changed while they judged (`moved`). A decision that read a moved source read data at least as new as its versions, never older. To reconstruct a decision, read the policy at its versions; if a source moved, the record says that its data may be newer than the version it names. Records written since 0.5 are format 4; older formats are read, and verified, as they were written.

The read set also holds the **attributes** the decision read from an `AttributeResolver`, by name, so a decision can be reconstructed with them:

- `v:` and the value's JSON, for a boolean, and for a value whose resolver declares the attribute public (`ResolvesPublicValues`): a page's status, not a person's department or age;
- `m:`, the MAC key's ID and the MAC of any other value, integers too, under the realm's [MAC key](keys.md#mac-keys);
- `w:`, withheld, when the realm has no MAC key, or the value is text that isn't valid UTF-8, which has no one form to prove.

Stored attributes (`AttributeStore`) aren't listed: the `policy` version covers them. Only a checked read set holds them, and a pinned one the log records; an unchecked one has `attributes: null`, as do records written before they were recorded. A decision reads each attribute once: a second read in the same decision is given the first value.

A MAC confirms a value given to it and discloses none. Ask with `DecisionConfirmations::confirm()`, or `yorvoid:decisions:confirm <decision> <attribute> <value>` (`--json` for a number, a boolean or a list): it says `matches`, `differs`, `not_recorded`, `withheld` or `unknown_key`, never the MAC. Every confirmation writes `yorvoid.decision.confirm` to the security log, with the record, the attribute's name and the result, never the value, since repeated confirmations are how a value is guessed. It's an administrator's API: who may confirm comes with the decision log's read side, and limiting how often is yours.

What the MAC protects, and what it doesn't:

- Whoever reads the database alone learns no value. They do see which decisions read equal values, since a MAC is the same for the same realm, attribute and value.
- Whoever holds the key file can guess a value with few possibilities, a department or a country, by trying each.
- A boolean's MAC would hide nothing, so it's recorded as it is.

It isn't a chain, and recording takes no lock: every request asks for decisions, reads included, and none waits for the realm's log or for each other.

What's recorded, and when:

- **An operation's Permit**, at once and before the operation runs, in a statement of its own. The operation's transaction starts afterwards, so a change rolled back still has its decision; if you call `Operations::run()` inside a transaction of your own, the record is part of yours, and lives and dies with what it allowed. If it can't be written, the operation doesn't run.
- **An operation's refusal, and a failed attempt**, outside your transaction, so your rollback doesn't take them, and without the realm's lock: at once on a connection of their own (`yorvoid.decision_log.connection`), or with no transaction open; otherwise once your outermost transaction has ended, committed or rolled back (`Transactions::afterEnd()`). A process that dies before then loses them. If one can't be written, the audit alert is raised, and the refusal stands. On a connection without YorvoID's `TransactionMiddleware`, the end can't be seen: they're written at once, in your transaction.
- **An ability's refusal or Indeterminate**, always, and its permit as configured: none by default, all, or a sampled share of requests, chosen per correlation ID so a request is recorded whole or not at all (in Symfony, a request and its sub-requests share one: [`RequestOrigins`](symfony.md#who-may-run-an-operation)). An ability declared with `recordsPermits: true` records its permits whatever the configuration, e.g. one for explaining someone else's access.
- Abilities' records wait for the end of what asked for them and are written together: call `DecisionLog::flush()` when a request, command or job ends (in Symfony, `kernel.terminate`, `console.terminate` and Messenger's handled and failed messages do), and whenever 100 are waiting. That's best effort: a process that dies before then loses them, and a write that fails raises the [audit alert](#when-a-record-cant-be-written), not an error.

```php
use YorvoID\Core\Authorization\DecisionLog\DecisionDigests;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Realm;

$decisions = new DecisionLog(new DbalDecisionLogStore($database), PermitRecording::Sampled, sampleRate: 0.01, transactions: $transactions);
$evaluator = Evaluator::builtIn($authority, versions: [$policyVersion, $relationsVersion], decisions: $decisions);
$log = new SecurityLog($logStore, $transactions, attempts: $decisions);  // failed attempts and refusals go to it

// From cron, before each checkpoint: the records written since the last digest.
$digests = new DecisionDigests(new DbalDecisionLogStore($database), $log, $logStore, $transactions);
$digests->take(Realm::default());
```

Every security-log entry recorded while an operation runs cites the decision in its details: `decision: {id, hash}`. Its consequences cite the same one. The chain then proves which decision allowed each change, and a decision record changed afterwards no longer matches the hash the chain holds.

Each record written is dispatched as a `DecisionRecorded` event, for your own audit log or a SIEM: refusals and failed attempts are no entries, so `SecurityLogEntryRecorded` doesn't carry them. One written once your transaction ended is dispatched then, maybe after the response was sent.

Old decisions are pruned by `yorvoid:purge` (`--keep-decisions`, 90 days by default), or `DecisionLog::purge()`. The security log keeps the hashes of those it cites.

In Symfony, `yorvoid.decision_log.permits` (`none`, `sampled` or `all`) and `sample_rate` configure it. A `DecisionLogStore` of your own passes `DecisionLogStoreContract` ([storage](storage.md#stores-of-your-own)).

## When a record can't be written

A record that can't be written, because the database is down, the disk is full or the connection was lost, is an audit failure (AUD-006, NIST AU-5). What happens depends on the log:

- **A security-log entry** fails the change it describes, always. The change and its entry are one transaction, so the log never lacks what was committed. This isn't configurable.
- **An operation's decision** has to be written before the operation runs, in every mode.
- **An ability's decision** goes ahead by default, and its record is written at the end of the request. Configured to refuse, it waits for its record (below).

Whatever the mode, every failed write raises the **audit alert**:

- a `critical` entry in the PSR-3 logger;
- then an `AuditFailed` event: which log, the realm, how many records were lost, and whether what they were for was refused.

Neither needs the database. Route them to whoever must act, e.g. a Monolog handler that pages, or a listener. A listener that throws is logged as an error and doesn't change the outcome.

The same alert is raised for two things that aren't records but must not go unnoticed: what was waiting for a transaction to end and failed (`after_end`, e.g. disabling a copied passkey, which then stays active until someone disables it), and a [security notification](notifications.md) that couldn't be sent (`security_notice`).

```php
use YorvoID\Core\Log\Event\AuditFailed;

$dispatcher->addListener(AuditFailed::class, static function (AuditFailed $failure) use ($pager): void {
    $pager->page(sprintf('YorvoID lost %d %s record(s): %s', $failure->records, $failure->log, $failure->error->getMessage()));
});
```

### Refusing without a record

With `AuditFailure::Refuse` (in Symfony, `yorvoid.audit.on_failure: refuse`), nothing is permitted without its record:

- Every decision that's recorded is written **before it's returned**. Nothing waits for the end of the request, and a batch from `decideAbilities()` is one write.
- If the write fails, a Permit or Permit-offline becomes **Indeterminate** with the reason `audit_unavailable`. It keeps its ID, and the audit alert is its trace.
- It needs a connection of its own (`yorvoid.decision_log.connection`; the Symfony bridge refuses to start without one): a record written in your transaction is undone with it.
- A Deny stays a Deny.
- A voter then denies access, and `Operations::run()` throws `NotPermitted`; the handler doesn't run.

```php
use YorvoID\Core\Authorization\DecisionLog\AuditFailure;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;

$decisions = new DecisionLog(
    new DbalDecisionLogStore($database, $auditConnection),
    PermitRecording::All,
    onFailure: AuditFailure::Refuse,
    events: $dispatcher,
);
```

Two settings keep that promise:

- **A connection of its own.** On the application's connection, a record written inside a transaction of yours (a Messenger handler with `doctrine_transaction`, say) is rolled back with it, after what it allowed may already have happened, e.g. an e-mail sent. Give `DbalDecisionLogStore` a second connection to the same database (in Symfony, `yorvoid.decision_log.connection`, the name of a DoctrineBundle connection). Its records are committed when the decision is returned. That applies to operations' decisions in either mode too. On SQLite the second connection waits while the first one writes, so use it there for tests only.
- **Every Permit recorded.** A Permit that sampling leaves out isn't an audit record, so a failure can't refuse it. Set `permits: all` if every Permit must be on record.

`yorvoid:doctor` says which mode is configured, and warns when refusing lacks either setting. Refusing costs one write per recorded decision, where continuing writes at most one per request.

