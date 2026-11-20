// Delegation chains over time (06 §5.3, §7; I3 and I5 at decision time): delegations are issued,
// re-delegated, revoked and expire while uses come in, and the root principal can lose the authority
// they delegated. A use is accepted only if, at that moment, every link of its chain is active and
// unexpired, the chain is no deeper than its root allowed, and the root is permitted the action now.
// Revoking a delegation stops every delegation issued under it, at the next use.
//
// CACHED = false is YorvoID's way: each use reads the chain and the root's authority as they are now
// (with versions, so a cache never answers for a revoked state). CACHED = true remembers each
// delegation's validity from its issuance, as a cache without versions would, and must break SAFE.
//
// Bounds: 4 principals (a chain of 3 needs 4), 4 delegations, depth 3, time 0..4.
module chains {
  const CACHED: bool

  val PRINCIPALS = Set("ana", "agent1", "agent2", "agent3")
  val IDS = Set(1, 2, 3, 4)
  val MAX_DEPTH = 3
  val MAX_TIME = 4

  type Delegation = { delegator: str, delegate: str, parent: int, depth: int, expires: int, revoked: bool }

  // The delegations issued so far, by id; 0 is "no parent".
  var issued: int -> Delegation
  var now: int
  // Who the policy permits the delegated action now.
  var permitted: Set[str]
  // A cache of each delegation's validity at its issuance: used only when CACHED.
  var cached: int -> bool
  // Whether a use was ever accepted while its chain wasn't valid.
  var acceptedInvalid: bool
  // Uses accepted, for the traces.
  var accepted: int
  // The longest chain a use was accepted under: a witness that deep chains are reached.
  var longestUsed: int

  // The delegation's chain, itself first, up to the root (MAX_DEPTH + 1 links at most).
  def chainOf(d: int): Set[int] =
    0.to(MAX_DEPTH).fold((Set(d), d), (acc, _) =>
      val p = if (issued.keys().contains(acc._2)) issued.get(acc._2).parent else 0
      if (p == 0 or not(issued.keys().contains(p))) acc else (acc._1.union(Set(p)), p)
    )._1

  def rootOf(d: int): int = chainOf(d).filter(x => issued.get(x).parent == 0).fold(0, (_, x) => x)

  // Valid now: every link active and unexpired, the chain acyclic and rooted, and the root permitted.
  def validNow(d: int): bool = and {
    issued.keys().contains(d),
    chainOf(d).forall(x => issued.keys().contains(x)),
    chainOf(d).forall(x => not(issued.get(x).revoked) and now < issued.get(x).expires),
    rootOf(d) != 0,
    permitted.contains(issued.get(rootOf(d)).delegator),
  }

  action init = all {
    issued' = Map(),
    now' = 0,
    permitted' = Set("ana"),
    cached' = Map(),
    acceptedInvalid' = false,
    accepted' = 0,
    longestUsed' = 0,
  }

  // A principal delegates their own authority: the root of a chain (issuing it is itself authorized).
  action issueRoot(id: int, delegator: str, delegate: str, expires: int): bool = all {
    not(issued.keys().contains(id)),
    permitted.contains(delegator),
    delegator != delegate,
    now < expires,
    issued' = issued.put(id, { delegator: delegator, delegate: delegate, parent: 0, depth: MAX_DEPTH - 1, expires: expires, revoked: false }),
    cached' = cached.put(id, true),
    now' = now, permitted' = permitted, acceptedInvalid' = acceptedInvalid, accepted' = accepted, longestUsed' = longestUsed,
  }

  // A delegate passes part of it on, within the parent: only while the parent's chain is valid, with
  // depth left, no later expiry, and never back to someone in the chain.
  action issueChild(id: int, parent: int, delegate: str, expires: int): bool = all {
    not(issued.keys().contains(id)),
    validNow(parent),
    issued.get(parent).depth > 0,
    expires <= issued.get(parent).expires,
    now < expires,
    chainOf(parent).forall(x => issued.get(x).delegator != delegate),
    issued.get(parent).delegate != delegate,
    issued' = issued.put(id, { delegator: issued.get(parent).delegate, delegate: delegate, parent: parent, depth: issued.get(parent).depth - 1, expires: expires, revoked: false }),
    cached' = cached.put(id, true),
    now' = now, permitted' = permitted, acceptedInvalid' = acceptedInvalid, accepted' = accepted, longestUsed' = longestUsed,
  }

  action revoke(id: int): bool = all {
    issued.keys().contains(id),
    issued' = issued.set(id, { ...issued.get(id), revoked: true }),
    now' = now, permitted' = permitted, cached' = cached, acceptedInvalid' = acceptedInvalid, accepted' = accepted, longestUsed' = longestUsed,
  }

  action tick: bool = all {
    now < MAX_TIME,
    now' = now + 1,
    issued' = issued, permitted' = permitted, cached' = cached, acceptedInvalid' = acceptedInvalid, accepted' = accepted, longestUsed' = longestUsed,
  }

  // The policy changes: a principal stops, or starts, being permitted the action (e.g. Ana stops being
  // an editor).
  action losePermission(p: str): bool = all {
    permitted.contains(p),
    permitted' = permitted.exclude(Set(p)),
    issued' = issued, now' = now, cached' = cached, acceptedInvalid' = acceptedInvalid, accepted' = accepted, longestUsed' = longestUsed,
  }

  action gainPermission(p: str): bool = all {
    not(permitted.contains(p)),
    permitted' = permitted.union(Set(p)),
    issued' = issued, now' = now, cached' = cached, acceptedInvalid' = acceptedInvalid, accepted' = accepted, longestUsed' = longestUsed,
  }

  // The delegate acts under the delegation: the decision checks the chain now (or, CACHED, remembers).
  action actUnder(id: int): bool = all {
    issued.keys().contains(id),
    if (CACHED) cached.get(id) else validNow(id),
    acceptedInvalid' = (acceptedInvalid or not(validNow(id))),
    accepted' = accepted + 1,
    longestUsed' = if (chainOf(id).size() > longestUsed) chainOf(id).size() else longestUsed,
    issued' = issued, now' = now, permitted' = permitted, cached' = cached,
  }

  // The choices are drawn from what can apply: a new id for an issuance, an issued one for the rest.
  action step = any {
    all {
      IDS.exclude(issued.keys()) != Set(),
      now < MAX_TIME,
      nondet id = IDS.exclude(issued.keys()).oneOf()
      nondet p = PRINCIPALS.oneOf()
      nondet q = PRINCIPALS.oneOf()
      nondet expires = (now + 1).to(MAX_TIME).oneOf()
      any {
        issueRoot(id, p, q, expires),
        all {
          issued.keys() != Set(),
          nondet parent = issued.keys().oneOf()
          issueChild(id, parent, q, expires),
        },
      },
    },
    all {
      issued.keys() != Set(),
      nondet id = issued.keys().oneOf()
      any { revoke(id), actUnder(id) },
    },
    nondet p = PRINCIPALS.oneOf()
    any { losePermission(p), gainPermission(p) },
    tick,
  }

  // No use is ever accepted while its chain isn't valid: revoked or expired anywhere in it, or the
  // root no longer permitted.
  val SAFE = not(acceptedInvalid)

  // Chains stay acyclic and within their root's depth.
  val WELL_FORMED = issued.keys().forall(d => chainOf(d).size() <= MAX_DEPTH and chainOf(d).forall(x => issued.get(x).depth >= 0))

  val SAFETY = SAFE and WELL_FORMED

  // A witness, expected to be violated: a use accepted under a chain of three delegations is reachable,
  // so SAFE isn't vacuous.
  val NO_DEEP_USE = longestUsed < 3
}

module checked {
  import chains(CACHED = false).*
}

module cachedWithoutVersions {
  import chains(CACHED = true).*
}
