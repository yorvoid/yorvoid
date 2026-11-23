/*
 * Delegation and attenuation: when a chain of delegations is valid at a use, and what that guarantees.
 *
 * Source: requirements 06 §5 (delegations, attenuation, validating a chain at use, what can't be
 * delegated) and §10 (I3), with M3-1's choices (requirements/m3-1-models-design.md §2, M3, M4):
 * - actions are the catalogue's, expanded at issuance: a delegation holds a set of actions, never a
 *   pattern that would match actions added later;
 * - a resource selector is a type and a conjunction of atoms; a child narrows its parent's with more
 *   atoms, or with a smaller id set in place of the parent's id atom;
 * - purposes form a tree: a child's purpose is its parent's or below it.
 *
 * The implementation checks, at a use: the leaf delegation admits the request; each link is within its
 * parent (as issued, re-checked now); every link is active and inside its window; the chain is acyclic
 * and ends at the actor; and the root principal is permitted the request now. The assertions show
 * that this is enough: every link of the chain then admits the request too (I3, on every dimension),
 * and the root's authority now bounds it.
 *
 * Checked: exhaustively within the bounds of each command (up to 4 delegations, 3 actions, 3 atoms,
 * 3 purposes, times 0..4). Run: java -jar alloy.jar exec -f spec/delegation/attenuation.als
 */
module attenuation

open util/ordering[Time] as T

sig Time {}
sig Principal {}
sig Execution {}
sig Requirement {}
sig Condition {}

-- The catalogue's actions, each from the time it was added; some can't be delegated (06 §5.4).
sig Action { addedAt: one Time }
sig NonDelegable in Action {}

-- Registered purposes, a tree.
sig Purpose { above: lone Purpose }
fact PurposesFormATree { no p: Purpose | p in p.^above }

-- A purpose and every purpose below it.
fun within[p: Purpose]: set Purpose { { q: Purpose | q = p or p in q.^above } }

sig Type {}
sig Id {}

-- A selector's conjuncts: a set of ids, or another constraint (an attribute's value, a relation),
-- which a child can only repeat exactly.
abstract sig Atom {}
sig IdsAtom extends Atom { ids: set Id }
sig OtherAtom extends Atom {}

sig Selector { type: one Type, atoms: set Atom }

-- A resource: its type and id, and the other constraints it satisfies.
sig Resource { rtype: one Type, rid: one Id, holds: set OtherAtom }

pred matches[r: Resource, s: Selector] {
    r.rtype = s.type
    all a: s.atoms | (a in IdsAtom implies r.rid in a.ids) and (a in OtherAtom implies a in r.holds)
}

-- A selector within another (M3): the same type, and each of the parent's atoms repeated, or, for an
-- id atom, replaced by one whose ids are a subset.
pred narrows[child, parent: Selector] {
    child.type = parent.type
    all pa: parent.atoms | some ca: child.atoms |
        ca = pa or (pa in IdsAtom and ca in IdsAtom and ca.ids in pa.ids)
}

sig Delegation {
    delegator: one Principal,
    delegate: one Principal,
    parent: lone Delegation,
    actions: set Action,
    redelegable: set Action,
    selector: one Selector,
    purpose: one Purpose,
    issuedAt: one Time,
    notBefore: one Time,
    expires: one Time,
    depth: one Int,
    execution: lone Execution,
    requirements: set Requirement,
    conditions: set Condition,
    revoked: lone Delegation        -- itself when revoked
} {
    -- Facts only for typing and the bound: what a stored record can break, the use checks (validAtUse).
    revoked in this
    depth <= 4      -- within the bound's integers, so no arithmetic wraps
}

-- Issued within its parent (06 §5.2): what the issuance checked, and the use checks again.
pred withinParent[d: Delegation] {
    let p = d.parent {
        d.delegator = p.delegate
        d.actions in p.redelegable
        d.redelegable in p.redelegable
        narrows[d.selector, p.selector]
        d.purpose in within[p.purpose]
        T/lte[p.notBefore, d.notBefore]
        T/lte[d.expires, p.expires]
        d.depth = minus[p.depth, 1]
        T/lte[p.issuedAt, d.issuedAt]
        some p.execution implies d.execution = p.execution
        p.requirements in d.requirements
        p.conditions in d.conditions
    }
}

-- Issued at all: nothing that can't be delegated (06 §5.4), and only actions the catalogue had when it
-- was issued: a pattern was expanded then (M4).
pred issuable[d: Delegation] {
    no d.actions & NonDelegable
    all a: d.actions | T/lte[a.addedAt, d.issuedAt]
}

-- What a stored record must hold by itself, which the use checks because records come from a database.
pred wellFormed[d: Delegation] {
    d.redelegable in d.actions
    d.depth >= 0
}

sig Request {
    actor: one Principal,
    action: one Action,
    resource: one Resource,
    rpurpose: one Purpose,
    at: one Time,
    rexecution: lone Execution,
    satisfies: set Requirement,     -- the evidence the request has (06 §6)
    meets: set Condition            -- the conditions it meets
}

-- The root principal's own authority now, as the policy decides it at the use (06 §5.3).
sig Policy { permits: Principal -> Action -> Resource }

-- One delegation admits the request: its own scope, nothing about its chain.
pred admits[d: Delegation, r: Request] {
    r.action in d.actions
    matches[r.resource, d.selector]
    r.rpurpose in within[d.purpose]
    T/lte[d.notBefore, r.at] and T/lt[r.at, d.expires]      -- the window: now < expiry (J14)
    some d.execution implies r.rexecution = d.execution
    d.requirements in r.satisfies
    d.conditions in r.meets
}

fun chain[d: Delegation]: set Delegation { d.*parent }
fun root[d: Delegation]: Delegation { { x: chain[d] | no x.parent } }

-- What the implementation checks at a use (06 §5.3).
pred validAtUse[d: Delegation, r: Request, pol: Policy] {
    d not in d.^parent                                  -- no cycle
    one root[d]
    d.delegate = r.actor                                -- ends at the actor
    admits[d, r]                                        -- the leaf's scope
    all x: chain[d] {
        no x.revoked
        wellFormed[x]
        issuable[x]
        T/lte[x.notBefore, r.at] and T/lt[r.at, x.expires]
        some x.parent implies withinParent[x]
    }
    let rt = root[d] | rt.delegator -> r.action -> r.resource in pol.permits   -- the root, now
}

/* I3: when a chain is valid at a use, every link of it admits the request, on every dimension, and the
 * root is permitted it now: Authority(actor) ⊆ … ⊆ Authority(root, now). */
assert Attenuation {
    all d: Delegation, r: Request, pol: Policy |
        validAtUse[d, r, pol] implies
            (all x: chain[d] | admits[x, r]) and root[d].delegator -> r.action -> r.resource in pol.permits
}
check Attenuation for 4 but 5 Time, 3 Action, 4 Atom, 3 Id, 3 Purpose, 2 Request, 2 Policy, 5 Int expect 0

/* Definitional (a regression pin, not evidence: it restates a conjunct of validAtUse).
 * 06 §5.4: no valid chain carries an action that can't be delegated. */
assert NothingNonDelegableIsDelegated {
    all d: Delegation, r: Request, pol: Policy | validAtUse[d, r, pol] implies r.action not in NonDelegable
}
check NothingNonDelegableIsDelegated for 4 but 5 Time, 3 Action, 3 Purpose, 5 Int expect 0

/* Re-delegation depth: a valid chain is no longer than its root allowed (depth counts down to 0). */
assert DepthIsBounded {
    all d: Delegation, r: Request, pol: Policy |
        validAtUse[d, r, pol] implies #chain[d] <= plus[root[d].depth, 1]
}
check DepthIsBounded for 4 but 5 Time, 3 Action, 3 Purpose, 6 Int expect 0

/* Definitional (a regression pin, not evidence: it restates a conjunct of validAtUse).
 * A revoked or expired link anywhere in the chain stops every use under it. */
assert ARevokedOrExpiredLinkStopsTheChain {
    all d: Delegation, r: Request, pol: Policy, x: Delegation |
        (x in chain[d] and (some x.revoked or T/lte[x.expires, r.at])) implies not validAtUse[d, r, pol]
}
check ARevokedOrExpiredLinkStopsTheChain for 4 but 5 Time, 3 Action, 3 Purpose, 5 Int expect 0

/* Definitional (a regression pin, not evidence: it restates a conjunct of validAtUse).
 * The root's authority is re-evaluated now: if the root principal isn't permitted the request now,
 * nothing they delegated is (06 §5.3, "if Ana stops being an editor"). */
assert TheRootLosingAuthorityStopsTheChain {
    all d: Delegation, r: Request, pol: Policy |
        root[d].delegator -> r.action -> r.resource not in pol.permits implies not validAtUse[d, r, pol]
}
check TheRootLosingAuthorityStopsTheChain for 4 but 5 Time, 3 Action, 3 Purpose, 5 Int expect 0

/* M4: an action added to the catalogue after the root delegation was issued is never used under the
 * chain: every link was issued with the actions the catalogue had then, and links come after their
 * parents. */
assert ALaterActionIsntDelegated {
    all d: Delegation, r: Request, pol: Policy |
        validAtUse[d, r, pol] implies T/lte[r.action.addedAt, root[d].issuedAt]
}
check ALaterActionIsntDelegated for 4 but 5 Time, 3 Action, 3 Purpose, 5 Int expect 0

-- Instances exist: a valid chain of depth 3, so the checks above aren't vacuous.
run ADepthThreeChainIsValid {
    some d: Delegation, r: Request, pol: Policy | #chain[d] = 3 and validAtUse[d, r, pol]
} for 4 but 5 Time, 3 Action, 3 Purpose, 5 Int expect 1

/*
 * Broken variants, which must fail (spec/check.sh "violated"): each drops one part of the check, and
 * the checker must find the request it then lets through.
 */

-- Without the root's re-evaluation: a chain still passes after the root lost the authority.
pred validWithoutTheRoot[d: Delegation, r: Request] {
    d not in d.^parent and one root[d] and d.delegate = r.actor and admits[d, r]
    all x: chain[d] | no x.revoked and wellFormed[x] and issuable[x] and T/lte[x.notBefore, r.at] and T/lt[r.at, x.expires] and (some x.parent implies withinParent[x])
}
assert BrokenWithoutTheRoot {
    all d: Delegation, r: Request, pol: Policy |
        validWithoutTheRoot[d, r] implies root[d].delegator -> r.action -> r.resource in pol.permits
}
check BrokenWithoutTheRoot for 3 but 4 Time, 2 Action, 2 Purpose, 5 Int expect 1

-- Id narrowing the wrong way round (a child's ids a superset of the parent's): an ancestor no longer
-- admits what the leaf does.
pred narrowsReversed[child, parent: Selector] {
    child.type = parent.type
    all pa: parent.atoms | some ca: child.atoms | ca = pa or (pa in IdsAtom and ca in IdsAtom and pa.ids in ca.ids)
}
pred validWithReversedIds[d: Delegation, r: Request, pol: Policy] {
    d not in d.^parent and one root[d] and d.delegate = r.actor and admits[d, r]
    all x: chain[d] | no x.revoked and wellFormed[x] and issuable[x] and T/lte[x.notBefore, r.at] and T/lt[r.at, x.expires] and
        (some x.parent implies (x.delegator = x.parent.delegate and x.actions in x.parent.redelegable and
            narrowsReversed[x.selector, x.parent.selector] and x.purpose in within[x.parent.purpose] and
            T/lte[x.parent.notBefore, x.notBefore] and T/lte[x.expires, x.parent.expires]))
    root[d].delegator -> r.action -> r.resource in pol.permits
}
assert BrokenReversedIds {
    all d: Delegation, r: Request, pol: Policy | validWithReversedIds[d, r, pol] implies (all x: chain[d] | admits[x, r])
}
check BrokenReversedIds for 3 but 4 Time, 2 Action, 4 Atom, 3 Id, 2 Purpose, 5 Int expect 1

-- Without checking that a record's re-delegable actions are among its own: a child re-delegates an
-- action its parent never held, and the parent no longer admits the request (A1).
pred validWithoutRedelegableInActions[d: Delegation, r: Request, pol: Policy] {
    d not in d.^parent and one root[d] and d.delegate = r.actor and admits[d, r]
    all x: chain[d] | no x.revoked and x.depth >= 0 and issuable[x] and T/lte[x.notBefore, r.at] and T/lt[r.at, x.expires] and (some x.parent implies withinParent[x])
    root[d].delegator -> r.action -> r.resource in pol.permits
}
assert BrokenWithoutRedelegableInActions {
    all d: Delegation, r: Request, pol: Policy | validWithoutRedelegableInActions[d, r, pol] implies (all x: chain[d] | admits[x, r])
}
check BrokenWithoutRedelegableInActions for 3 but 4 Time, 2 Action, 2 Purpose, 5 Int expect 1

-- Without checking that a record's depth isn't negative: a chain longer than its root allowed (A1).
pred validWithoutDepthCheck[d: Delegation, r: Request, pol: Policy] {
    d not in d.^parent and one root[d] and d.delegate = r.actor and admits[d, r]
    all x: chain[d] | no x.revoked and x.redelegable in x.actions and issuable[x] and T/lte[x.notBefore, r.at] and T/lt[r.at, x.expires] and (some x.parent implies withinParent[x])
    root[d].delegator -> r.action -> r.resource in pol.permits
}
assert BrokenWithoutDepthCheck {
    all d: Delegation, r: Request, pol: Policy | validWithoutDepthCheck[d, r, pol] implies #chain[d] <= plus[root[d].depth, 1]
}
check BrokenWithoutDepthCheck for 4 but 5 Time, 2 Action, 2 Purpose, 5 Int expect 1

/* Fixtures for the PHP check of one link (yorvoid/delegation's Attenuation): a root delegation and one
  passed on from it, over a small scope, and whether the model has the child within its parent. Each
  selector has one type and at most one id atom, as the PHP one does; both records are well formed, as
  the store keeps them; no execution, requirement or condition, which the PHP tests cover apart. Read by
  delegation/fixtures.mjs, replayed by tests/Delegation/AttenuationFixturesTest.php; the second run is the
  same with a purpose under another, which the first's sample doesn't reach. */
abstract sig Verdict {}
one sig Within, Outside extends Verdict {}

one sig Link { child: one Delegation, given: one Delegation, verdict: one Verdict }

pred fixture {
    Link.child.parent = Link.given
    no Link.given.parent
    Link.child != Link.given
    #Delegation = 2
    one Type
    #Principal = 3
    Link.given.delegate = Link.child.delegator
    Link.given.delegator != Link.child.delegate
    Link.given.delegate != Link.child.delegate
    all d: Delegation {
        wellFormed[d]
        lone d.selector.atoms & IdsAtom
        all a: d.selector.atoms & IdsAtom | some a.ids
        no d.execution and no d.requirements and no d.conditions and no d.revoked
        d.issuedAt = T/first
        T/lt[d.notBefore, d.expires]
        some d.actions
    }
    Link.given.depth = 1
    Link.child.depth in 0 + 1
    no NonDelegable
    all a: Action | a.addedAt = T/first
    Link.verdict = (withinParent[Link.child] implies Within else Outside)
}

run fixtures { fixture } for 2 but exactly 2 Delegation, exactly 3 Principal, exactly 2 Action, 2 Atom, 1 Id, 2 Selector, 2 Purpose, 3 Time, 0 Execution, 0 Requirement, 0 Condition, 0 Resource, 0 Request, 0 Policy, 4 Int
run fixturesTree { fixture and some above } for 2 but exactly 2 Delegation, exactly 3 Principal, exactly 2 Action, 2 Atom, 1 Id, 2 Selector, exactly 2 Purpose, 3 Time, 0 Execution, 0 Requirement, 0 Condition, 0 Resource, 0 Request, 0 Policy, 4 Int
