// The intent verifier's ring (INT-005, INT-006, INT-013, INT-016, INT-017): the first key comes with the
// bootstrap secret, at once; every later change, adding or removing a key, is signed by a key the
// verifier has, waits a delay while any key can cancel it, and never leaves the verifier without ring.
//
// COUNT_LEAVING = true is YorvoID's way (Verifier::removeKey counts the ring already leaving);
// COUNT_LEAVING = false counts only the current keys, which must break NEVER_WITHOUT_KEYS: two removals
// asked for at once, each leaving one key, leave none.
module keyRegistry {
  type Kind = Add | Remove
  type Change = { key: str, kind: Kind, effectiveAt: int }

  const KEYS: Set[str]
  const DELAY: int
  const MAX_TIME: int
  const COUNT_LEAVING: bool

  var ring: Set[str]
  var changes: Set[Change]
  var now: int
  // The bootstrap secret: whether one works, and whether it was ever used.
  var secret: bool
  var bootstrapped: bool
  // A key that took effect without waiting the delay, other than the first: must never happen.
  var early: bool
  var secretsUsed: int

  def leaving: Set[str] = changes.filter(c => c.kind == Remove).map(c => c.key)
  def coming: Set[str] = changes.filter(c => c.kind == Add).map(c => c.key)
  def known(k: str): bool = ring.contains(k) or coming.contains(k)

  action init = all {
    ring' = Set(), changes' = Set(), now' = 0, secret' = false, bootstrapped' = false, early' = false, secretsUsed' = 0,
  }

  // The host's root creates a secret (createBootstrapSecret): one at a time.
  action createSecret = all {
    secret' = true,
    ring' = ring, changes' = changes, now' = now, bootstrapped' = bootstrapped, early' = early, secretsUsed' = secretsUsed,
  }

  // bootstrap(): the secret works once. The first key is added at once; with ring, the new one waits.
  action bootstrap(k: str): bool = all {
    secret,
    not(known(k)),
    secret' = false,
    secretsUsed' = secretsUsed + 1,
    bootstrapped' = true,
    if (ring == Set()) all { ring' = Set(k), changes' = changes }
    else all { ring' = ring, changes' = changes.union(Set({ key: k, kind: Add, effectiveAt: now + DELAY })) },
    now' = now, early' = early,
  }

  // addKey(): signed by a key the verifier has.
  action addKey(k: str): bool = all {
    ring != Set(),
    not(known(k)),
    changes' = changes.union(Set({ key: k, kind: Add, effectiveAt: now + DELAY })),
    ring' = ring, now' = now, secret' = secret, bootstrapped' = bootstrapped, early' = early, secretsUsed' = secretsUsed,
  }

  // removeKey(): signed by a key the verifier has; never the last one.
  action removeKey(k: str): bool = all {
    ring.contains(k),
    not(leaving.contains(k)),
    if (COUNT_LEAVING) ring.size() - leaving.size() > 1 else ring.size() > 1,
    changes' = changes.union(Set({ key: k, kind: Remove, effectiveAt: now + DELAY })),
    ring' = ring, now' = now, secret' = secret, bootstrapped' = bootstrapped, early' = early, secretsUsed' = secretsUsed,
  }

  // cancelKeyChange(): signed by a key the verifier has, before the change takes effect.
  action cancel(c: Change): bool = all {
    ring != Set(),
    changes.contains(c),
    changes' = changes.exclude(Set(c)),
    ring' = ring, now' = now, secret' = secret, bootstrapped' = bootstrapped, early' = early, secretsUsed' = secretsUsed,
  }

  // applyKeyChanges(): every change that's due takes effect.
  action apply = {
    val due = changes.filter(c => now >= c.effectiveAt)
    all {
      due != Set(),
      ring' = ring.union(due.filter(c => c.kind == Add).map(c => c.key)).exclude(due.filter(c => c.kind == Remove).map(c => c.key)),
      changes' = changes.exclude(due),
      early' = (early or due.exists(c => c.kind == Add and now < c.effectiveAt)),
      now' = now, secret' = secret, bootstrapped' = bootstrapped, secretsUsed' = secretsUsed,
    }
  }

  action tick = all {
    now < MAX_TIME,
    now' = now + 1,
    ring' = ring, changes' = changes, secret' = secret, bootstrapped' = bootstrapped, early' = early, secretsUsed' = secretsUsed,
  }

  action nothingHappens = all {
    ring' = ring, changes' = changes, now' = now, secret' = secret, bootstrapped' = bootstrapped, early' = early, secretsUsed' = secretsUsed,
  }

  action step = any {
    createSecret,
    nondet k = KEYS.oneOf()
    any { bootstrap(k), addKey(k), removeKey(k) },
    nondet c = changes.oneOf()
    cancel(c),
    apply,
    tick,
    nothingHappens,
  }

  // Once the verifier has a key, it always has one: every operation stays possible to sign.
  val NEVER_WITHOUT_KEYS = bootstrapped implies ring != Set()

  // Only the first key arrives at once; every later one waited the delay, while owners could cancel it.
  val NOTHING_EARLY = not(early)

  // A key is never added twice, nor both coming and leaving.
  val CONSISTENT = coming.intersect(ring) == Set() and coming.intersect(leaving) == Set() and leaving.subseteq(ring)

  val SAFETY = NEVER_WITHOUT_KEYS and NOTHING_EARLY and CONSISTENT
}

module counted {
  import keyRegistry(KEYS = Set("k1", "k2", "k3"), DELAY = 1, MAX_TIME = 3, COUNT_LEAVING = true).*
}

module uncounted {
  import keyRegistry(KEYS = Set("k1", "k2", "k3"), DELAY = 1, MAX_TIME = 3, COUNT_LEAVING = false).*
}
