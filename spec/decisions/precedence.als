/*
 * Decision precedence: the outcome of an authorization decision from the conditions of the policy
 * elements that match a request and the validity of the authority behind it.
 *
 * Source: requirements 06 §3 (the precedence table). The PHP evaluator (M2) is tested against the
 * outcome function below, so this file is the specification, not a description of the code.
 *
 * Checked: every property below holds for all combinations of up to 6 matching elements (exhaustive
 * within that bound). Run: java -jar alloy.jar exec -f spec/decisions/precedence.als
 */
module precedence

-- What evaluating a condition can give.
abstract sig Value {}
one sig True, False, Unknown extends Value {}

abstract sig Effect {}
one sig PermitEffect, ForbidEffect extends Effect {}

-- A policy element whose target matches the request: a permit or a forbid, and its condition.
sig Element {
    effect: one Effect,
    condition: one Value
}

-- The authority behind the request: validated, known to be invalid, or not establishable.
abstract sig Authority {}
one sig Valid, Invalid, AuthorityUnknown extends Authority {}

abstract sig Outcome {}
one sig Permit, Deny, Indeterminate extends Outcome {}

-- The precedence table of 06 §3, first matching row wins, for elements with these effects and values.
fun table[es: set Element, eff: Element -> Effect, v: Element -> Value, a: Authority]: one Outcome {
    (some e: es | e.eff = ForbidEffect and e.v = True) => Deny                      -- 1
    else (some e: es | e.eff = ForbidEffect and e.v = Unknown) => Indeterminate     -- 2
    else a = Invalid => Deny                                                     -- 3
    else a = AuthorityUnknown => Indeterminate                                   -- 4
    else (some e: es | e.eff = PermitEffect and e.v = True) => Permit               -- 5
    else (some e: es | e.eff = PermitEffect and e.v = Unknown) => Indeterminate     -- 6
    else Deny                                                                    -- 7
}

-- The table for the elements as they are.
fun outcome[es: set Element, a: Authority]: one Outcome {
    table[es, effect, condition, a]
}

-- rs is es with every unknown condition made known (true or false), element by element.
pred resolves[es, rs: set Element, m: es -> rs] {
    m in es one -> one rs
    all e: es {
        m[e].effect = e.effect
        e.condition != Unknown implies m[e].condition = e.condition
        m[e].condition != Unknown
    }
}

-- An authority made known.
pred resolvesAuthority[a, b: Authority] {
    a = AuthorityUnknown implies b in Valid + Invalid else b = a
}

/* P1 (06 §10): a Permit never relies on an unknown: however the unknowns turn out, it stays a Permit.
 * So Indeterminate can't be "rounded up" to Permit by any fallback that guesses. */
assert PermitDoesntDependOnUnknowns {
    all es, rs: set Element, m: es -> rs, a, b: Authority |
        (outcome[es, a] = Permit and resolves[es, rs, m] and resolvesAuthority[a, b])
            implies outcome[rs, b] = Permit
}
check PermitDoesntDependOnUnknowns for 6 Element expect 0

/* P3 (06 §10): adding a forbid never turns a refusal into a Permit. */
assert AddingAForbidNeverPermits {
    all es: set Element, e: Element, a: Authority |
        (e.effect = ForbidEffect and outcome[es, a] != Permit) implies outcome[es + e, a] != Permit
}
check AddingAForbidNeverPermits for 6 Element expect 0

/* P3: making a permit's condition less certain (true to unknown or false, unknown to false) never
 * turns a refusal into a Permit. (Authority getting worse is covered by PermitNeedsEverything: only a
 * valid authority permits.) */
assert WeakeningNeverPermits {
    all es: set Element, e, w: Element, a: Authority |
        (e in es and w not in es and w.effect = e.effect and e.effect = PermitEffect
            and (e.condition = True implies w.condition in Unknown + False)
            and (e.condition = Unknown implies w.condition = False)
            and e.condition != False
            and outcome[es, a] != Permit)
        implies outcome[es - e + w, a] != Permit
}
check WeakeningNeverPermits for 6 Element expect 0

/* Permit needs a validated authority and a permit whose condition holds, and no forbid that holds
 * or might hold. */
assert PermitNeedsEverything {
    all es: set Element, a: Authority | outcome[es, a] = Permit implies {
        a = Valid
        some e: es | e.effect = PermitEffect and e.condition = True
        no e: es | e.effect = ForbidEffect and e.condition in True + Unknown
    }
}
check PermitNeedsEverything for 6 Element expect 0

/* The outcome depends only on the set of matching elements, never on their order: by construction,
 * the function above takes a set. The evaluator must be equivalent to it (M2's model-based tests). */

/* Indeterminate is honest: when it's reported, the unknowns could make the outcome a Permit.
 *
 * This doesn't hold, by design of 06 §3, and the counterexample is documented here rather than
 * hidden: an unknown forbid with no permit that could apply gives Indeterminate, although every
 * resolution gives Deny. Row 2 reports "something couldn't be evaluated" as an operational signal
 * even when it can't change the result. Whether row 2 should give Deny when no permit could apply
 * is open question Q-09. */
assert IndeterminateOnlyWhenItCouldMatter {
    -- "Some resolution of the unknowns permits", stated first-order (Alloy can't search for the
    -- resolution itself): a permit that holds or might, no forbid that surely holds, and an
    -- authority that isn't known to be invalid. Unknown forbids can always resolve to false.
    all es: set Element, a: Authority | outcome[es, a] = Indeterminate implies {
        some e: es | e.effect = PermitEffect and e.condition in True + Unknown
        no e: es | e.effect = ForbidEffect and e.condition = True
        a != Invalid
    }
}
check IndeterminateOnlyWhenItCouldMatter for 6 Element expect 1

-- Every outcome is reachable: the table has no dead rows.
run SomePermit { some es: set Element, a: Authority | outcome[es, a] = Permit } for 3 Element expect 1
run SomeDeny { some es: set Element, a: Authority | outcome[es, a] = Deny } for 3 Element expect 1
run SomeIndeterminate { some es: set Element, a: Authority | outcome[es, a] = Indeterminate } for 3 Element expect 1

/* Permit-offline (XP-03, P1′): an outcome of its own, which an OfflineDecider may give while a store is
 * unreachable. The table's outcome is Indeterminate first; the offline path is asked only when every
 * reason for that is an unreachable store (never for an unknown forbid: unknown_forbid is a reason of
 * its own), for an operation that accepts it, where a permit could have applied, and the decider holds.
 * The PHP evaluator's tests check the same conditions (tests/Core/Authorization/OfflineTest.php). */
one sig PermitOffline extends Outcome {}

abstract sig Flag {}
one sig On, Off extends Flag {}

-- For elements with these effects and values.
fun offlineTable[es: set Element, eff: Element -> Effect, v: Element -> Value, a: Authority, unreachable: set Element, authorityUnreachable, decider, accepts, holds: Flag]: one Outcome {
    (table[es, eff, v, a] = Indeterminate
        and decider = On and accepts = On and holds = On
        and (no e: es | e.eff = ForbidEffect and e.v = Unknown)
        and (a = AuthorityUnknown
            implies authorityUnreachable = On
            else (all e: es | (e.eff = PermitEffect and e.v = Unknown) implies e in unreachable))
        and (some e: es | e.eff = PermitEffect and e.v in True + Unknown))
    => PermitOffline else table[es, eff, v, a]
}

fun offlineOutcome[es: set Element, a: Authority, unreachable: set Element, authorityUnreachable, decider, accepts, holds: Flag]: one Outcome {
    offlineTable[es, effect, condition, a, unreachable, authorityUnreachable, decider, accepts, holds]
}

/* P1′: Permit-offline arises only from Indeterminate, only with a decider registered, only for an
 * operation that accepts it, and never with a forbid that couldn't be ruled out. */
assert PermitOfflineOnlyFromAnUnreachableStore {
    all es, u: set Element, a: Authority, au, d, acc, h: Flag |
        offlineOutcome[es, a, u, au, d, acc, h] = PermitOffline implies {
            outcome[es, a] = Indeterminate
            d = On and acc = On
            no e: es | e.effect = ForbidEffect and e.condition = Unknown
        }
}
check PermitOfflineOnlyFromAnUnreachableStore for 6 Element expect 0

/* The offline path never gives a Permit, nor takes one away (P1 holds as written). */
assert OfflineNeverPermits {
    all es, u: set Element, a: Authority, au, d, acc, h: Flag |
        (offlineOutcome[es, a, u, au, d, acc, h] = Permit) iff (outcome[es, a] = Permit)
}
check OfflineNeverPermits for 6 Element expect 0

/* Without a decider, or for an operation that doesn't accept it, nothing changes: off by default. */
assert OffByDefault {
    all es, u: set Element, a: Authority, au, h: Flag |
        offlineOutcome[es, a, u, au, Off, On, h] = outcome[es, a] and offlineOutcome[es, a, u, au, On, Off, h] = outcome[es, a]
}
check OffByDefault for 6 Element expect 0

-- And it does happen: the checks above aren't about an empty set.
run SomePermitOffline {
    some es, u: set Element, a: Authority, au: Flag | offlineOutcome[es, a, u, au, On, On, On] = PermitOffline
} for 6 Element expect 1

/* The two configurations (XP-16, MP-1): the extension points at their default (enterprise), and all
 * engaged (extended). The points are offline decisions (XP-03): an OfflineDecider registered, for an
 * operation that accepts Permit-offline; and the pessimistic mode of ordered conditions (XP-15, M2-11),
 * below. */
abstract sig ExtensionPoint {}
one sig OfflineDecisions, PessimisticOrdering extends ExtensionPoint {}

fun decide[es: set Element, a: Authority, unreachable: set Element, authorityUnreachable, holds: Flag, engaged: set ExtensionPoint]: one Outcome {
    offlineOutcome[es, a, unreachable, authorityUnreachable, (OfflineDecisions in engaged => On else Off), On, holds]
}

/* MP-1: with nothing engaged, the decision is the table, whatever stores are unreachable. */
assert DefaultIsTheTable {
    all es, u: set Element, a: Authority, au, h: Flag | decide[es, a, u, au, h, none] = outcome[es, a]
}
check DefaultIsTheTable for 6 Element expect 0

/* P1, in the default configuration: a Permit needs a validated authority and a permit that holds, and
 * no forbid that holds or might. */
assert DefaultPermitNeedsEverything {
    all es, u: set Element, a: Authority, au, h: Flag | decide[es, a, u, au, h, none] = Permit implies {
        a = Valid
        some e: es | e.effect = PermitEffect and e.condition = True
        no e: es | e.effect = ForbidEffect and e.condition in True + Unknown
    }
}
check DefaultPermitNeedsEverything for 6 Element expect 0

/* P1′, in the extended configuration: Permit-offline only from an Indeterminate that a store unreachable
 * caused, and a Permit only where the default gives one. */
assert ExtendedOnlyAddsPermitOffline {
    all es, u: set Element, a: Authority, au, h: Flag | let x = decide[es, a, u, au, h, ExtensionPoint] {
        x != outcome[es, a] implies (x = PermitOffline and outcome[es, a] = Indeterminate)
    }
}
check ExtendedOnlyAddsPermitOffline for 6 Element expect 0

/* The fixtures the PHP evaluator is tested against (tests/Core/Authorization/PrecedenceFixturesTest.php):
 * every request with up to 6 matching elements, its authority, and the outcome the table gives it, in
 * the default configuration. decisions/fixtures.mjs enumerates them into decisions/fixtures.json;
 * check.sh fails if that file isn't what the model gives. */
lone sig Request {
    elements: set Element,
    authority: one Authority,
    result: one Outcome
}

fact fixture {
    some Request implies {
        Request.elements = Element
        Request.result = decide[Request.elements, Request.authority, none, Off, Off, none]
    }
}

run fixtures { one Request and no ExtendedRequest and no OrderedRequest and no Ordered } for 6 Element

/* And in the extended configuration, for an operation that accepts Permit-offline: which unknown
 * conditions an unreachable store caused, whether the authority's was, and what the decider says, up to
 * 4 matching elements. decisions/fixtures.mjs extended enumerates them into
 * decisions/fixtures-extended.json. */
lone sig ExtendedRequest {
    elements: set Element,
    unreachable: set Element,
    authority: one Authority,
    authorityUnreachable: one Flag,
    holds: one Flag,
    result: one Outcome
}

fact extendedFixture {
    some ExtendedRequest implies {
        ExtendedRequest.elements = Element
        ExtendedRequest.unreachable in condition.Unknown
        ExtendedRequest.authority != AuthorityUnknown implies ExtendedRequest.authorityUnreachable = Off
        ExtendedRequest.result = decide[ExtendedRequest.elements, ExtendedRequest.authority, ExtendedRequest.unreachable, ExtendedRequest.authorityUnreachable, ExtendedRequest.holds, ExtensionPoint]
    }
}

run fixturesExtended { one ExtendedRequest and no Request and no OrderedRequest and no Ordered } for 4 Element

/* Ordered conditions (XP-15, M2-11): a rule whose condition is on an ordered fact, e.g. "readiness ≥ 2",
 * on a bounded scale, higher being worse (here 0..3). The fact is known (now), or unknown with or without
 * a last known value. A rule answers as RuleElements makes it the evaluator's elements: a forbid rule is
 * a forbid that holds when its condition does; a permit rule, a permit that holds when its requirement
 * is met; and either, when it can't tell, an unknown forbid. In the default configuration an unknown fact
 * can't be told (missing data); with PessimisticOrdering engaged, it's decided at every value it may
 * have, from the last known one to the top of the scale: a forbid applies if it applies at any. A
 * requirement counts as unmet (RDY-04) unless it's met at every value of the scale: assuming the worst
 * of the fact is no reason to meet one, even one that a worse value would meet. */
abstract sig Level {}
one sig L0, L1, L2, L3 extends Level {}

fun rank: Level -> Int { L0 -> 0 + L1 -> 1 + L2 -> 2 + L3 -> 3 }

abstract sig Direction {}
one sig AtLeast, AtMost extends Direction {}

-- The elements that are rules on the ordered fact; their own condition plays no part.
sig Ordered in Element {
    direction: one Direction,
    bound: one Level
}

pred holdsAt[e: Ordered, l: Level] {
    e.direction = AtLeast implies l.rank >= e.bound.rank else l.rank <= e.bound.rank
}

-- The values the fact may have: itself when known; else every value from the last known one, or from
-- the bottom of the scale with none, to the top.
fun possible[now, last: lone Level]: set Level {
    some now => now else { l: Level | l.rank >= (some last => last.rank else 0) }
}

-- What a rule on the fact answers: True (refuses, or permits), False (neither) or Unknown.
fun answer[e: Ordered, now, last: lone Level, engaged: set ExtensionPoint]: one Value {
    some now => (holdsAt[e, now] => True else False)
    else PessimisticOrdering not in engaged => Unknown
    else e.effect = ForbidEffect => ((some l: possible[now, last] | holdsAt[e, l]) => True else False)
    else ((all l: Level | holdsAt[e, l]) => True else False)
}

-- The elements as the evaluator sees them: a rule that can't tell is an unknown forbid.
fun orderedEffect[es: set Element, now, last: lone Level, engaged: set ExtensionPoint]: Element -> Effect {
    { e: es, f: Effect | (e in Ordered and answer[e, now, last, engaged] = Unknown) => f = ForbidEffect else f = e.effect }
}

fun orderedValue[es: set Element, now, last: lone Level, engaged: set ExtensionPoint]: Element -> Value {
    { e: es, x: Value | e in Ordered => x = answer[e, now, last, engaged] else x = e.condition }
}

-- The decision with the ordered fact as it is. It's the table on the rules' answers: an ordered rule
-- adds neither an unreachable store nor a reason to ask the offline decider, so the offline path is as
-- above, on these elements.
fun decideOrdered[es: set Element, a: Authority, now, last: lone Level, engaged: set ExtensionPoint]: one Outcome {
    table[es, orderedEffect[es, now, last, engaged], orderedValue[es, now, last, engaged], a]
}

/* Both extension points together: the offline path on the rules' answers. Whatever the configuration,
 * a forbid on the ordered fact that holds at a value the fact may have leaves neither a Permit nor a
 * Permit-offline: unknown by default, it's an unknown forbid, which the offline path never passes;
 * pessimistic, it holds. */
fun decideAll[es: set Element, a: Authority, unreachable: set Element, authorityUnreachable, holds: Flag, now, last: lone Level, engaged: set ExtensionPoint]: one Outcome {
    offlineTable[es, orderedEffect[es, now, last, engaged], orderedValue[es, now, last, engaged], a, unreachable, authorityUnreachable, (OfflineDecisions in engaged => On else Off), On, holds]
}

assert AnOrderedForbidThatMayHoldNeverPermitsOffline {
    all es, u: set Element, a: Authority, au, h: Flag, now, last: lone Level, x: set ExtensionPoint |
        (lone now and (some now implies no last)
            and some e: es & Ordered | e.effect = ForbidEffect and (some l: possible[now, last] | holdsAt[e, l]))
        implies decideAll[es, a, u, au, h, now, last, x] not in Permit + PermitOffline
}
check AnOrderedForbidThatMayHoldNeverPermitsOffline for 4 Element expect 0

-- And with no rule on the fact, both together are the offline path as it was.
assert WithoutOrderedRulesItsTheOfflinePath {
    all es, u: set Element, a: Authority, au, h: Flag, now, last: lone Level, x: set ExtensionPoint |
        no es & Ordered implies decideAll[es, a, u, au, h, now, last, x] = decide[es, a, u, au, h, x]
}
check WithoutOrderedRulesItsTheOfflinePath for 4 Element expect 0

-- Permit-offline still happens with a rule on the fact, so the first check isn't vacuous.
run SomePermitOfflineWithAnOrderedRule {
    some es, u: set Element, a: Authority, au: Flag, last: lone Level |
        some es & Ordered and decideAll[es, a, u, au, On, none, last, ExtensionPoint] = PermitOffline
} for 3 Element expect 1

-- How permissive an outcome is.
fun permissiveness: Outcome -> Int { Deny -> 0 + Indeterminate -> 1 + PermitOffline -> 1 + Permit -> 2 }

/* R2 (XP-15, RDY-04), in the extended configuration: with the fact unknown, the decision is never more
 * permissive than at any value the fact may have; in particular never a Permit that one of them denies. */
assert UnknownIsNoMorePermissive {
    all es: set Element, a: Authority, last: lone Level, l: possible[none, last] |
        decideOrdered[es, a, none, last, ExtensionPoint].permissiveness <= decideOrdered[es, a, l, none, ExtensionPoint].permissiveness
}
check UnknownIsNoMorePermissive for 4 Element expect 0

-- It's the pessimistic mode that gives it: in the default configuration it doesn't hold (an unknown forbid
-- is Indeterminate where a value it may have is a Deny), so the check above isn't vacuous.
assert DefaultUnknownIsNoMorePermissive {
    all es: set Element, a: Authority, last: lone Level, l: possible[none, last] |
        decideOrdered[es, a, none, last, none].permissiveness <= decideOrdered[es, a, l, none, none].permissiveness
}
check DefaultUnknownIsNoMorePermissive for 4 Element expect 1

/* MP-1, in the default configuration: a rule on an unknown fact can't tell, so unless a forbid that holds
 * decides it, the decision is Indeterminate (missing data, in the evaluator's reasons). */
assert DefaultUnknownOrderedIsIndeterminate {
    all es: set Element, a: Authority, last: lone Level |
        (some es & Ordered and no e: es - Ordered | e.effect = ForbidEffect and e.condition = True)
            implies decideOrdered[es, a, none, last, none] = Indeterminate
}
check DefaultUnknownOrderedIsIndeterminate for 4 Element expect 0

-- The mode is about an unknown fact only: with it known, both configurations decide alike; and without
-- rules on it, the decision is the table.
assert KnownDecidesAlike {
    all es: set Element, a: Authority, l: Level |
        decideOrdered[es, a, l, none, none] = decideOrdered[es, a, l, none, ExtensionPoint]
}
check KnownDecidesAlike for 4 Element expect 0

assert WithoutOrderedRulesItsTheTable {
    all es: set Element, a: Authority, now, last: lone Level, x: set ExtensionPoint |
        no es & Ordered implies decideOrdered[es, a, now, last, x] = outcome[es, a]
}
check WithoutOrderedRulesItsTheTable for 4 Element expect 0

-- Engaged, an unknown fact with no history is a Deny under a forbid on any value: the mode's point, not a
-- bug (RDY-04).
run NoHistoryDenies {
    some e: Ordered | e.effect = ForbidEffect and decideOrdered[e, Valid, none, none, ExtensionPoint] = Deny
} for 1 Element expect 1

/* The fixtures of ordered conditions (tests/Policy/OrderedFixturesTest.php): every request of up to 2
 * matching elements, plain or rules on the fact, with each authority and each state of the fact, and
 * what each configuration decides. decisions/fixtures.mjs ordered enumerates them into
 * decisions/fixtures-ordered.json. */
lone sig OrderedRequest {
    elements: set Element,
    authority: one Authority,
    now: lone Level,
    last: lone Level,
    default: one Outcome,
    extended: one Outcome
}

fact orderedFixture {
    some OrderedRequest implies {
        OrderedRequest.elements = Element
        some OrderedRequest.now implies no OrderedRequest.last
        all e: Ordered | e.condition = False
        OrderedRequest.default = decideOrdered[Element, OrderedRequest.authority, OrderedRequest.now, OrderedRequest.last, none]
        OrderedRequest.extended = decideOrdered[Element, OrderedRequest.authority, OrderedRequest.now, OrderedRequest.last, ExtensionPoint]
    }
}

run fixturesOrdered { one OrderedRequest and no Request and no ExtendedRequest } for 2 Element
