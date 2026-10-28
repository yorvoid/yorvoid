// One-time tokens (requirement ATN-030): a token works only until it expires, only while it's the
// newest for its purpose, and at most once, also when several processes present it at the same moment.
//
// ATOMIC = true is YorvoID's way (check and use in one step); ATOMIC = false is the naive way (read,
// then delete), which must break AT_MOST_ONCE.
module oneTimeToken {
  type TokenState = Unissued | Valid | Revoked | Used

  const TOKENS: Set[str]
  const CONSUMERS: Set[str]
  const ATOMIC: bool
  const MAX_TIME: int
  const LIFETIME: int

  var state: str -> TokenState
  var expiry: str -> int
  var now: int
  // Each consumer: the token it checked and is about to use (naive way only), or "" for none.
  var checked: str -> str
  var granted: str -> int
  var stale: bool

  def works(t: str): bool = state.get(t) == Valid and now < expiry.get(t)

  action init = all {
    state' = TOKENS.mapBy(_ => Unissued),
    expiry' = TOKENS.mapBy(_ => 0),
    now' = 0,
    checked' = CONSUMERS.mapBy(_ => ""),
    granted' = TOKENS.mapBy(_ => 0),
    stale' = false,
  }

  // Issuing a token for the purpose revokes the earlier ones (newest only).
  action issue(t: str): bool = all {
    state.get(t) == Unissued,
    state' = TOKENS.mapBy(x => if (x == t) Valid else if (state.get(x) == Valid) Revoked else state.get(x)),
    expiry' = expiry.set(t, now + LIFETIME),
    now' = now, checked' = checked, granted' = granted, stale' = stale,
  }

  action tick: bool = all {
    now < MAX_TIME,
    now' = now + 1,
    state' = state, expiry' = expiry, checked' = checked, granted' = granted, stale' = stale,
  }

  // YorvoID's way: check and use in one step.
  action consumeAtomically(c: str, t: str): bool = all {
    ATOMIC,
    checked.get(c) == "",
    works(t),
    state' = state.set(t, Used),
    granted' = granted.setBy(t, n => n + 1),
    expiry' = expiry, now' = now, checked' = checked, stale' = stale,
  }

  // The naive way, step 1: check that the token works.
  action check(c: str, t: str): bool = all {
    not(ATOMIC),
    checked.get(c) == "",
    works(t),
    checked' = checked.set(c, t),
    state' = state, expiry' = expiry, now' = now, granted' = granted, stale' = stale,
  }

  // The naive way, step 2: delete it and go ahead, trusting the earlier check.
  action use(c: str): bool = {
    val t = checked.get(c)
    all {
      t != "",
      state' = state.set(t, Used),
      granted' = granted.setBy(t, n => n + 1),
      stale' = (stale or not(works(t))),
      checked' = checked.set(c, ""),
      expiry' = expiry, now' = now,
    }
  }

  action step = any {
    nondet t = TOKENS.oneOf()
    issue(t),
    tick,
    nondet c = CONSUMERS.oneOf()
    nondet t = TOKENS.oneOf()
    any { consumeAtomically(c, t), check(c, t) },
    nondet c = CONSUMERS.oneOf()
    use(c),
    // The model is bounded: once every token is issued and the clock stops, nothing more happens.
    nothingHappens,
  }

  action nothingHappens = all {
    state' = state, expiry' = expiry, now' = now, checked' = checked, granted' = granted, stale' = stale,
  }

  // A token is used at most once.
  val AT_MOST_ONCE = TOKENS.forall(t => granted.get(t) <= 1)

  // Nobody goes ahead with a token that was revoked, used or expired at that moment.
  val NEVER_STALE = not(stale)

  // At most one token works at a time: the newest.
  val NEWEST_ONLY = tuples(TOKENS, TOKENS).forall(((t, u)) => (state.get(t) == Valid and state.get(u) == Valid) implies t == u)

  val SAFETY = AT_MOST_ONCE and NEVER_STALE and NEWEST_ONLY
}

module atomic {
  import oneTimeToken(TOKENS = Set("t1", "t2", "t3"), CONSUMERS = Set("c1", "c2", "c3"), ATOMIC = true, MAX_TIME = 3, LIFETIME = 2).*
}

module naive {
  import oneTimeToken(TOKENS = Set("t1", "t2", "t3"), CONSUMERS = Set("c1", "c2", "c3"), ATOMIC = false, MAX_TIME = 3, LIFETIME = 2).*
}
