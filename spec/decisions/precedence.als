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

-- The precedence table of 06 §3, first matching row wins.
fun outcome[es: set Element, a: Authority]: one Outcome {
    (some e: es | e.effect = ForbidEffect and e.condition = True) => Deny               -- 1
    else (some e: es | e.effect = ForbidEffect and e.condition = Unknown) => Indeterminate -- 2
    else a = Invalid => Deny                                                         -- 3
    else a = AuthorityUnknown => Indeterminate                                       -- 4
    else (some e: es | e.effect = PermitEffect and e.condition = True) => Permit        -- 5
    else (some e: es | e.effect = PermitEffect and e.condition = Unknown) => Indeterminate -- 6
    else Deny                                                                        -- 7
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

fun offlineOutcome[es: set Element, a: Authority, unreachable: set Element, authorityUnreachable, decider, accepts, holds: Flag]: one Outcome {
    (outcome[es, a] = Indeterminate
        and decider = On and accepts = On and holds = On
        and (no e: es | e.effect = ForbidEffect and e.condition = Unknown)
        and (a = AuthorityUnknown
            implies authorityUnreachable = On
            else (all e: es | (e.effect = PermitEffect and e.condition = Unknown) implies e in unreachable))
        and (some e: es | e.effect = PermitEffect and e.condition in True + Unknown))
    => PermitOffline else outcome[es, a]
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

/* The fixtures the PHP evaluator is tested against (tests/Core/Authorization/PrecedenceFixturesTest.php):
 * every request with up to 6 matching elements, its authority, and the outcome the table gives it.
 * decisions/fixtures.mjs enumerates them into decisions/fixtures.json; check.sh fails if that file isn't
 * what the model gives. */
one sig Request {
    elements: set Element,
    authority: one Authority,
    result: one Outcome
}

fact fixture {
    Request.elements = Element
    Request.result = outcome[Request.elements, Request.authority]
}

run fixtures {} for 6 Element
