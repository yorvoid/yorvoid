// Claiming a realm with its setup code (ATN-020, TOK-09, ATN-034): the code works once, for one person,
// also when several present it at the same moment; whoever loses gets no principal. And it works only
// while the realm has no active principal, also when one is created at the same moment another way, e.g.
// by an invitation accepted (architecture audit A-10): the owner is the realm's first principal.
//
// Each claimer checks the code and that the realm has no active principal, creates its principal, and
// marks the realm claimed, in one transaction: a rollback undoes the principal. ATOMIC = true is YorvoID's
// way (the mark is a compare-and-swap, "UPDATE … WHERE claimed_at IS NULL", and the loser rolls back);
// ATOMIC = false marks it whatever it holds, which must break ONE_OWNER when nothing else serializes the
// claims (LOCKED = false too).
//
// An invitation accepted creates a principal and records it in the security log, which locks the realm's
// log until it commits: it's one step here, taken only while nobody else holds the lock. LOCKED = true is
// YorvoID's way: a claim locks the realm's log before it checks, and holds it until it commits.
// LOCKED = false checks without the lock, which must break OWNER_FIRST.
module setupClaim {
  type Phase = Idle | Checked | Created | Won | Lost

  const CLAIMERS: Set[str]
  const ATOMIC: bool
  const LOCKED: bool

  var claimed: bool
  var phase: str -> Phase
  // Principals created by claims that committed.
  var owners: Set[str]
  // Whether an invitation was accepted: an active principal that isn't an owner.
  var invited: bool
  // Who holds the realm's log lock, "" for nobody.
  var lock: str
  // The realm's first principal to commit, "" while there is none.
  var first: str

  action init = all {
    claimed' = false,
    phase' = CLAIMERS.mapBy(_ => Idle),
    owners' = Set(),
    invited' = false,
    lock' = "",
    first' = "",
  }

  // Locks the realm's log (if LOCKED), then reads: the code matches, the realm isn't claimed yet, and it
  // has no active principal that committed.
  action check(c: str): bool = all {
    phase.get(c) == Idle,
    not(LOCKED) or lock == "",
    not(claimed),
    not(invited),
    phase' = phase.set(c, Checked),
    lock' = if (LOCKED) c else lock,
    claimed' = claimed, owners' = owners, invited' = invited, first' = first,
  }

  // Creates the principal, inside the transaction: nobody sees it before the commit.
  action create(c: str): bool = all {
    phase.get(c) == Checked,
    phase' = phase.set(c, Created),
    claimed' = claimed, owners' = owners, invited' = invited, lock' = lock, first' = first,
  }

  // Marks the realm claimed and commits; or, if the mark finds it claimed already, rolls back. Either way,
  // the lock is released.
  action commit(c: str): bool = all {
    phase.get(c) == Created,
    lock' = if (lock == c) "" else lock,
    invited' = invited,
    if (ATOMIC and claimed) all {
      phase' = phase.set(c, Lost),
      claimed' = claimed, owners' = owners, first' = first,
    } else all {
      phase' = phase.set(c, Won),
      claimed' = true,
      owners' = owners.union(Set(c)),
      first' = if (first == "") c else first,
    },
  }

  // An invitation accepted: a principal created, its log entry appended under the lock, committed.
  action accept: bool = all {
    not(invited),
    lock == "",
    invited' = true,
    first' = if (first == "") "invitee" else first,
    claimed' = claimed, phase' = phase, owners' = owners, lock' = lock,
  }

  action nothingHappens = all { claimed' = claimed, phase' = phase, owners' = owners, invited' = invited, lock' = lock, first' = first }

  action step = any {
    nondet c = CLAIMERS.oneOf()
    any { check(c), create(c), commit(c) },
    accept,
    nothingHappens,
  }

  // The realm has one first owner, at most.
  val ONE_OWNER = owners.size() <= 1

  // An owner is the realm's first principal: a claim never succeeds after someone else became active.
  val OWNER_FIRST = owners.forall(o => first == o)

  // Whoever lost the race has no principal: its transaction rolled back.
  val LOSERS_HAVE_NOTHING = CLAIMERS.forall(c => phase.get(c) == Lost implies not(owners.contains(c)))

  // Claimed exactly when someone owns it.
  val CLAIMED_MEANS_OWNED = claimed == (owners != Set())

  val SAFETY = ONE_OWNER and OWNER_FIRST and LOSERS_HAVE_NOTHING and CLAIMED_MEANS_OWNED
}

module atomic {
  import setupClaim(CLAIMERS = Set("c1", "c2", "c3"), ATOMIC = true, LOCKED = true).*
}

module naive {
  import setupClaim(CLAIMERS = Set("c1", "c2", "c3"), ATOMIC = false, LOCKED = false).*
}

module unlocked {
  import setupClaim(CLAIMERS = Set("c1", "c2", "c3"), ATOMIC = true, LOCKED = false).*
}
