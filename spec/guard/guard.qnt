// The guard (06 §7, D-10; I5 and P4): an effect in the same database as YorvoID's state runs in one
// transaction with a re-check of the authority it was permitted on. It locks what the decision read
// (D-52: the realm's lock first, as a write), reads it again, refuses if it was revoked, consumes the
// single-use permit, and commits with the effect. A revocation takes the same lock. So either the effect
// commits before the revocation, or it sees it: a guarded effect never commits on revoked authority (I5),
// and a single-use permit is consumed at most once, by the effect it names (P4).
//
// Three effect transactions, e1 and e1again (two attempts of the effect the permit names, e.g. a
// request sent twice) and e2 (another effect trying the same permit), and a revoker, under
// ISOLATION "rc" (READ COMMITTED: a read sees what's committed) or "rr" (REPEATABLE READ: a transaction
// reads from its snapshot, taken at its first statement; writing a row that changed since then
// conflicts, 40001, and the transaction runs again).
//
// MODE "guard" is YorvoID's: the lock is a write (an update of the lock row), taken before the read.
// Broken, each must break I5:
// - "checkThenLock": the authority is read before the lock is taken (a time-of-check/time-of-use gap);
// - "readLock": the lock is SELECT ... FOR UPDATE only, which writes nothing: under REPEATABLE READ, a
//   transaction that waited for it goes on with its old snapshot (G-5's R6, reproduced on PostgreSQL).
//   Under READ COMMITTED it's still safe, as the check shows.
// - "revokeNoLock": the revoker commits without taking the lock, under either isolation level.
// And each must break P4: "noCas", the permit consumed without a compare-and-swap; "noBinding", the
// permit consumed by an effect it doesn't name.
module guard {
  const MODE: str
  const ISOLATION: str

  type Phase = Idle | Started | Locked | Read | Done

  val TXS = Set("e1", "e1again", "e2", "revoker")
  // The attempts of the effect the permit names.
  val NAMED = Set("e1", "e1again")

  // Commits so far: the database's order of commits; a snapshot is the count when it was taken.
  var commits: int
  // Who holds the lock, "" for nobody; and when the lock row was last written, as a commit count.
  var holder: str
  var lockRowWrittenAt: int
  // The authority: whether it's revoked, and at which commit (0 for never).
  var revoked: bool
  var revokedAt: int
  // The single-use permit: who consumed it, "" for nobody.
  var consumedBy: str
  var phase: str -> Phase
  var snapshot: str -> int
  var sawRevoked: str -> bool
  // Effects committed, and whether one committed while the authority was revoked.
  var effects: int
  var onRevoked: bool
  var conflicts: int

  def readsRevoked(t: str): bool =
    if (ISOLATION == "rr") revoked and revokedAt <= snapshot.get(t) else revoked

  action unchanged = all {
    commits' = commits, holder' = holder, lockRowWrittenAt' = lockRowWrittenAt, revoked' = revoked,
    revokedAt' = revokedAt, consumedBy' = consumedBy, effects' = effects, onRevoked' = onRevoked,
  }

  action init = all {
    commits' = 0, holder' = "", lockRowWrittenAt' = 0, revoked' = false, revokedAt' = 0,
    consumedBy' = "", phase' = TXS.mapBy(_ => Idle), snapshot' = TXS.mapBy(_ => 0),
    sawRevoked' = TXS.mapBy(_ => false), effects' = 0, onRevoked' = false, conflicts' = 0,
  }

  // The transaction's first statement: its snapshot. In "checkThenLock" an effect reads first.
  action start(t: str): bool = all {
    phase.get(t) == Idle,
    phase' = phase.set(t, Started),
    snapshot' = snapshot.set(t, commits),
    sawRevoked' = if (MODE == "checkThenLock" and t != "revoker") sawRevoked.set(t, readsRevoked(t)) else sawRevoked,
    conflicts' = conflicts,
    unchanged,
  }

  // Takes the lock, waiting while another holds it. A lock that writes conflicts under REPEATABLE READ
  // when the lock row was written since the snapshot: the transaction rolls back and runs again.
  action lock(t: str): bool = all {
    phase.get(t) == Started,
    holder == "",
    if (ISOLATION == "rr" and MODE != "readLock" and lockRowWrittenAt > snapshot.get(t)) all {
      phase' = phase.set(t, Idle), conflicts' = conflicts + 1, holder' = holder,
    } else all {
      phase' = phase.set(t, Locked), conflicts' = conflicts, holder' = t,
    },
    snapshot' = snapshot, sawRevoked' = sawRevoked,
    commits' = commits, lockRowWrittenAt' = lockRowWrittenAt, revoked' = revoked, revokedAt' = revokedAt,
    consumedBy' = consumedBy, effects' = effects, onRevoked' = onRevoked,
  }

  // The re-check under the lock (in "checkThenLock", it was done before).
  action read(t: str): bool = all {
    phase.get(t) == Locked,
    t != "revoker",
    phase' = phase.set(t, Read),
    sawRevoked' = if (MODE == "checkThenLock") sawRevoked else sawRevoked.set(t, readsRevoked(t)),
    snapshot' = snapshot, conflicts' = conflicts,
    unchanged,
  }

  // The effect commits with the permit's consumption, unless it saw the revocation, the permit isn't its,
  // or it's consumed already (the consumption is a compare-and-swap). Either way the lock is released.
  action commitEffect(t: str): bool = all {
    phase.get(t) == Read,
    val commitsIt = not(sawRevoked.get(t)) and (MODE == "noBinding" or NAMED.contains(t)) and (MODE == "noCas" or consumedBy == "")
    all {
      commits' = if (commitsIt) commits + 1 else commits,
      consumedBy' = if (commitsIt) t else consumedBy,
      effects' = if (commitsIt) effects + 1 else effects,
      onRevoked' = (onRevoked or (commitsIt and revoked)),
      lockRowWrittenAt' = if (commitsIt and MODE != "readLock") commits + 1 else lockRowWrittenAt,
    },
    holder' = "",
    phase' = phase.set(t, Done),
    revoked' = revoked, revokedAt' = revokedAt,
    snapshot' = snapshot, sawRevoked' = sawRevoked, conflicts' = conflicts,
  }

  // The revocation commits under the lock: from now on, the authority is revoked. In "revokeNoLock" it
  // commits once started, without the lock, and writes no lock row.
  action revoke: bool = all {
    phase.get("revoker") == Locked or (MODE == "revokeNoLock" and phase.get("revoker") == Started),
    not(revoked),
    commits' = commits + 1,
    revoked' = true,
    revokedAt' = commits + 1,
    lockRowWrittenAt' = if (MODE != "readLock" and phase.get("revoker") == Locked) commits + 1 else lockRowWrittenAt,
    holder' = if (holder == "revoker") "" else holder,
    phase' = phase.set("revoker", Done),
    consumedBy' = consumedBy, effects' = effects, onRevoked' = onRevoked,
    snapshot' = snapshot, sawRevoked' = sawRevoked, conflicts' = conflicts,
  }

  action nothingHappens = all {
    phase' = phase, snapshot' = snapshot, sawRevoked' = sawRevoked, conflicts' = conflicts, unchanged,
  }

  action step = any {
    nondet t = TXS.oneOf()
    any { start(t), lock(t), read(t), commitEffect(t) },
    revoke,
    nothingHappens,
  }

  // I5: no guarded effect commits on revoked authority.
  val I5 = not(onRevoked)

  // P4: the single-use permit is consumed at most once, and only by the effect it names.
  val P4 = effects <= 1 and (consumedBy == "" or NAMED.contains(consumedBy))

  val SAFETY = I5 and P4

  // Witnesses, expected to be violated: the effect commits, a revocation commits, and under REPEATABLE
  // READ a waiter conflicts, so the checks above aren't vacuous.
  val NO_EFFECT = effects == 0
  val NO_REVOCATION = not(revoked)
  val NO_CONFLICT = conflicts == 0
}

module guardRc { import guard(MODE = "guard", ISOLATION = "rc").* }
module guardRr { import guard(MODE = "guard", ISOLATION = "rr").* }
module checkThenLockRc { import guard(MODE = "checkThenLock", ISOLATION = "rc").* }
module readLockRc { import guard(MODE = "readLock", ISOLATION = "rc").* }
module readLockRr { import guard(MODE = "readLock", ISOLATION = "rr").* }
module noCasRc { import guard(MODE = "noCas", ISOLATION = "rc").* }
module revokeNoLockRc { import guard(MODE = "revokeNoLock", ISOLATION = "rc").* }
module revokeNoLockRr { import guard(MODE = "revokeNoLock", ISOLATION = "rr").* }
module noBindingRc { import guard(MODE = "noBinding", ISOLATION = "rc").* }
