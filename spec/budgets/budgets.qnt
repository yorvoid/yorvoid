// Budgets along a delegation chain (06 §5, P5; M3-1's §2.3 as amended by M1): a delegation has a limit,
// reserved from its delegator's remaining budget when it's issued. A delegation's spend is what it
// reserved for the delegations issued under it plus its own uses; a use draws down only the leaf's
// budget, as one compare-and-swap with its effect (an UPDATE ... WHERE spend < limit, its row count
// checked). Charging every delegation of the chain would count a use twice: in the leaf's uses and in
// its parent's reservation for the leaf. A delegation that's revoked or expires stops every delegation
// under it, and gives back to its delegator what it didn't consume.
//
// Invariants:
// - NO_OVERSPEND: no delegation's uses, its own and those under it, exceed its limit, and no spend does;
// - NO_FALSE_REFUSAL: a use is refused only when its leaf's budget has no room (or the leaf ended);
// - RETURNED: once a delegation has ended, its delegator holds for it exactly what it consumed.
//
// MODE "reserve" is YorvoID's. Broken, each must fail:
// - "checkThenCharge": the room is read, then the use is charged without checking again: two concurrent
//   uses of the last unit both commit (NO_OVERSPEND);
// - "noReservation": a delegation is issued without reserving its limit from its delegator, whose own
//   limit then doesn't bound what's used under it (NO_OVERSPEND);
// - "chargeChain": a use is charged to, and needs room in, every delegation of its chain (NO_FALSE_REFUSAL);
// - "noReturn": an ended delegation's reservation stays whole (RETURNED).
//
// Bounds: 4 delegations, a chain of depth 3 (1 -> 2 -> 3) and a sibling (1 -> 4), limits up to 2 under a
// root of 2, 2 concurrent uses.
module budgets {
  const MODE: str

  type Status = Unissued | Active | Ended
  type Phase = Idle | Read

  val IDS = Set(1, 2, 3, 4)
  // The tree the delegations are issued in: 1 is the root's; 0 is "no parent".
  val PARENT = Map(1 -> 0, 2 -> 1, 3 -> 2, 4 -> 1)
  // Every delegation under each one, at any depth.
  val UNDER = Map(1 -> Set(2, 3, 4), 2 -> Set(3), 3 -> Set(), 4 -> Set())
  val ROOT_LIMIT = 2
  val LIMITS = 1.to(2)
  val SLOTS = Set("u1", "u2")

  var status: int -> Status
  var limit: int -> int
  // Each delegation's own uses.
  var used: int -> int
  // What a delegation's delegator holds for it: its limit while it's active, what it consumed once it
  // ended.
  var held: int -> int
  // Concurrent uses: the delegation each is under, and whether it saw room when it read.
  var phase: str -> Phase
  var leaf: str -> int
  var sawRoom: str -> bool
  // Whether a use was refused while its leaf had room.
  var refusedWithRoom: bool
  // Witnesses: uses accepted and refused, reservations given back, and a use at depth 3.
  var uses: int
  var refusals: int
  var returns: int
  var deepUse: bool

  def children(p: int): Set[int] = IDS.filter(c => PARENT.get(c) == p)

  // What the delegation has spent of its limit: its own uses, and what it holds for those under it.
  def spend(d: int): int = children(d).filter(c => status.get(c) != Unissued).fold(used.get(d), (acc, c) => acc + held.get(c))

  // What was used, by it and under it.
  def consumed(d: int): int = UNDER.get(d).fold(used.get(d), (acc, x) => acc + used.get(x))

  def room(d: int): bool = status.get(d) == Active and spend(d) < limit.get(d)

  // The chain from the delegation up to the root, itself first.
  def chain(d: int): Set[int] = IDS.filter(x => x == d or UNDER.get(x).contains(d))

  def canCharge(d: int): bool = if (MODE == "chargeChain") chain(d).forall(x => room(x)) else room(d)

  action unchangedUses = all {
    phase' = phase, leaf' = leaf, sawRoom' = sawRoom, refusedWithRoom' = refusedWithRoom, uses' = uses,
    refusals' = refusals, deepUse' = deepUse,
  }

  action init = all {
    status' = IDS.mapBy(d => if (d == 1) Active else Unissued),
    limit' = IDS.mapBy(d => if (d == 1) ROOT_LIMIT else 0),
    used' = IDS.mapBy(_ => 0),
    held' = IDS.mapBy(_ => 0),
    phase' = SLOTS.mapBy(_ => Idle),
    leaf' = SLOTS.mapBy(_ => 0),
    sawRoom' = SLOTS.mapBy(_ => false),
    refusedWithRoom' = false,
    uses' = 0,
    refusals' = 0,
    returns' = 0,
    deepUse' = false,
  }

  // Issued under an active delegation, its limit reserved from the delegator's remaining budget in one
  // compare-and-swap. In "noReservation" nothing is reserved.
  action issue(d: int, l: int): bool = all {
    status.get(d) == Unissued,
    val p = PARENT.get(d)
    all {
      status.get(p) == Active,
      MODE == "noReservation" or spend(p) + l <= limit.get(p),
      status' = status.set(d, Active),
      limit' = limit.set(d, l),
      held' = held.set(d, if (MODE == "noReservation") 0 else l),
    },
    used' = used, returns' = returns,
    unchangedUses,
  }

  // A use reads its leaf's room: the check a decision makes before the effect.
  action begin(s: str, d: int): bool = all {
    phase.get(s) == Idle,
    status.get(d) == Active,
    phase' = phase.set(s, Read),
    leaf' = leaf.set(s, d),
    sawRoom' = sawRoom.set(s, canCharge(d)),
    status' = status, limit' = limit, used' = used, held' = held, returns' = returns,
    refusedWithRoom' = refusedWithRoom, uses' = uses, refusals' = refusals, deepUse' = deepUse,
  }

  // The charge commits with the effect, or the use is refused. YorvoID's checks the room again in the
  // same statement as the charge; "checkThenCharge" trusts what it read.
  action charge(s: str): bool = all {
    phase.get(s) == Read,
    val d = leaf.get(s)
    val ok = if (MODE == "checkThenCharge") sawRoom.get(s) and status.get(d) == Active else canCharge(d)
    all {
      used' = if (not(ok)) used
        else if (MODE == "chargeChain") IDS.mapBy(x => if (chain(d).contains(x)) used.get(x) + 1 else used.get(x))
        else used.set(d, used.get(d) + 1),
      uses' = if (ok) uses + 1 else uses,
      refusals' = if (ok) refusals else refusals + 1,
      refusedWithRoom' = (refusedWithRoom or (not(ok) and room(d))),
      deepUse' = (deepUse or (ok and d == 3)),
    },
    phase' = phase.set(s, Idle),
    leaf' = leaf, sawRoom' = sawRoom,
    status' = status, limit' = limit, held' = held, returns' = returns,
  }

  // Revoked or expired: the delegation and every active one under it end, and each one's delegator holds
  // for it only what it consumed. In "noReturn" the reservations stay whole.
  action end(d: int): bool = all {
    status.get(d) == Active,
    d != 1,
    val ending = Set(d).union(UNDER.get(d)).filter(x => status.get(x) == Active)
    all {
      status' = IDS.mapBy(x => if (ending.contains(x)) Ended else status.get(x)),
      held' = IDS.mapBy(x => if (ending.contains(x) and MODE != "noReturn") consumed(x) else held.get(x)),
      returns' = if (ending.exists(x => consumed(x) < held.get(x)) and MODE != "noReturn") returns + 1 else returns,
    },
    limit' = limit, used' = used,
    unchangedUses,
  }

  action step = any {
    nondet d = IDS.oneOf()
    nondet l = LIMITS.oneOf()
    nondet s = SLOTS.oneOf()
    any { issue(d, l), begin(s, d), charge(s), end(d) },
  }

  val NO_OVERSPEND = IDS.filter(d => status.get(d) != Unissued).forall(d => consumed(d) <= limit.get(d) and spend(d) <= limit.get(d))

  val NO_FALSE_REFUSAL = not(refusedWithRoom)

  val RETURNED = IDS.filter(d => status.get(d) == Ended).forall(d => held.get(d) == consumed(d))

  val SAFETY = NO_OVERSPEND and NO_FALSE_REFUSAL and RETURNED

  // Witnesses, expected to be violated, so the checks above aren't vacuous: uses are accepted and
  // refused, a reservation is given back, and a use is made at the end of a chain of three.
  val NO_USE = uses == 0
  val NO_REFUSAL = refusals == 0
  val NO_RETURN = returns == 0
  val NO_DEEP_USE = not(deepUse)
}

module reserve { import budgets(MODE = "reserve").* }
module checkThenCharge { import budgets(MODE = "checkThenCharge").* }
module noReservation { import budgets(MODE = "noReservation").* }
module chargeChain { import budgets(MODE = "chargeChain").* }
module noReturn { import budgets(MODE = "noReturn").* }
