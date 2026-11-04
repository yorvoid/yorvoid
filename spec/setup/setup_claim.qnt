// Claiming a realm with its setup code (ATN-020, TOK-09): the code works once, for one person, also
// when several present it at the same moment; whoever loses gets no principal.
//
// Each claimer checks the code, creates its principal, and marks the realm claimed, in one transaction:
// a rollback undoes the principal. ATOMIC = true is YorvoID's way (the mark is a compare-and-swap,
// "UPDATE … WHERE claimed_at IS NULL", and the loser rolls back); ATOMIC = false marks it whatever it
// holds, which must break ONE_OWNER.
module setupClaim {
  type Phase = Idle | Checked | Created | Won | Lost

  const CLAIMERS: Set[str]
  const ATOMIC: bool

  var claimed: bool
  var phase: str -> Phase
  // Principals created by claims that committed.
  var owners: Set[str]

  action init = all {
    claimed' = false,
    phase' = CLAIMERS.mapBy(_ => Idle),
    owners' = Set(),
  }

  // Reads the setup state: the code matches, and the realm isn't claimed yet.
  action check(c: str): bool = all {
    phase.get(c) == Idle,
    not(claimed),
    phase' = phase.set(c, Checked),
    claimed' = claimed, owners' = owners,
  }

  // Creates the principal, inside the transaction: nobody sees it before the commit.
  action create(c: str): bool = all {
    phase.get(c) == Checked,
    phase' = phase.set(c, Created),
    claimed' = claimed, owners' = owners,
  }

  // Marks the realm claimed and commits; or, if the mark finds it claimed already, rolls back.
  action commit(c: str): bool = all {
    phase.get(c) == Created,
    if (ATOMIC and claimed) all {
      phase' = phase.set(c, Lost),
      claimed' = claimed, owners' = owners,
    } else all {
      phase' = phase.set(c, Won),
      claimed' = true,
      owners' = owners.union(Set(c)),
    },
  }

  action nothingHappens = all { claimed' = claimed, phase' = phase, owners' = owners }

  action step = any {
    nondet c = CLAIMERS.oneOf()
    any { check(c), create(c), commit(c) },
    nothingHappens,
  }

  // The realm has one first owner, at most.
  val ONE_OWNER = owners.size() <= 1

  // Whoever lost the race has no principal: its transaction rolled back.
  val LOSERS_HAVE_NOTHING = CLAIMERS.forall(c => phase.get(c) == Lost implies not(owners.contains(c)))

  // Claimed exactly when someone owns it.
  val CLAIMED_MEANS_OWNED = claimed == (owners != Set())

  val SAFETY = ONE_OWNER and LOSERS_HAVE_NOTHING and CLAIMED_MEANS_OWNED
}

module atomic {
  import setupClaim(CLAIMERS = Set("c1", "c2", "c3"), ATOMIC = true).*
}

module naive {
  import setupClaim(CLAIMERS = Set("c1", "c2", "c3"), ATOMIC = false).*
}
