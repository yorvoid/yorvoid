/*
 * Joins: a synthesis that combines inputs obtained under different delegations, and when it's permitted.
 *
 * Source: requirements 06 §8 (the join contract), §3 (precedence) and §10 (P1-P3), with M3-1's outcomes
 * for the review scenarios R01-R24 and the matrix J01-J20 (requirements/m3-1-models-design.md §3). Those
 * outcomes are YorvoID's proposal, not yet reviewed outside the implementation.
 *
 * A join is decided by 06 §3's precedence, first match wins:
 * 1. a restriction the inputs carry, or one added to the join, forbids the purpose: Deny;
 * 2. a restriction can't be resolved: Indeterminate;
 * 3. the authority is known to be invalid (no synthesis delegation, an untrusted issuer, expired, revoked,
 *    another purpose, inputs outside its commitment, an input or the issuance decided Deny, a source
 *    grant's purpose or activity under the deployment's settings): Deny;
 * 4. a record is missing, dangling, inconsistent or ambiguous: Indeterminate;
 * 5. otherwise: Permit.
 * The union of the input grants is evidence, never authority (06 §8.3): only a delegation that covers
 * synthesis is.
 *
 * Each scenario below is a world in which exactly that row's fault is present: a run shows it exists,
 * a check that it has the row's outcome. The properties hold in both configurations (XP-16): the
 * default, and the extended one, where XP-04's root-anchor resolver is a nondeterministic step that
 * may only say Deny or Indeterminate. The broken variant accepts one input's evidence for another's.
 *
 * Not here: R19 and J20, an effect committed after a revocation, are the guard's (guard/guard.qnt).
 *
 * Checked: exhaustively within each command's bounds (3 inputs, 3 restrictions, 2 synthesis
 * delegations among 4). Run: java -jar alloy.jar exec -f spec/joins/joins.als
 */
module joins

open util/ordering[Time] as T

sig Time {}
sig Principal {}
sig Purpose {}
sig Digest {}
sig Fingerprint {}
sig Version {}
sig Label {}

abstract sig Outcome {}
one sig Permit, Deny, Indeterminate extends Outcome {}

abstract sig Op {}
one sig Acquire, Synthesize, Release extends Op {}

abstract sig Order {}
one sig InOrder, AnyOrder extends Order {}

-- The per-deployment settings (06 §8, R20 and R23); the default has both.
abstract sig Setting {}
one sig SourcePurposesConstrain, ActiveAtEachStage extends Setting {}
one sig Deployment { settings: set Setting }

abstract sig Config {}
one sig Default, Extended extends Config {}

-- A registered artifact and its digest; provenance nodes, which may name parents.
sig Artifact { registered: one Digest }
sig Node { nArtifact: one Artifact, nDigest: one Digest, up: set Node }
one sig Registry { nodes: set Node, trusted: set Principal, current: one Version }

-- A recorded decision: its outcome, the fingerprint of the request it decided, its policy version.
sig Decision { result: one Outcome, fingerprint: one Fingerprint, version: one Version }

-- A delegation, a source grant or a synthesis one, as its current record says.
sig Delegation {
    issuer: one Principal,
    delegate: one Principal,
    covers: set Op,
    purposes: set Purpose,
    commits: seq Artifact,                  -- the exact input set, in order where order is declared
    commitDigest: Artifact -> lone Digest,
    canonical: one Fingerprint,             -- the canonical issuance request's fingerprint
    issuance: lone Decision,                -- the issuance decision the record binds
    version: one Version,                   -- the policy version the record says it was issued under
    expires: one Time,
    issuedExpires: one Time,                -- the expiry the issuance recorded
    revoked: lone Delegation                -- itself when revoked
} { revoked in this }

sig Restriction { forbids: set Purpose }
sig RestrictionRef { resolves: lone Restriction }

-- An input's access evidence: the decision that let it be read, under its source grant, bound to it.
sig Evidence {
    boundArtifact: one Artifact,
    boundDigest: one Digest,
    grant: one Delegation,
    acquisition: one Fingerprint,
    decision: lone Decision
}

sig Input {
    artifact: one Artifact,
    digest: one Digest,
    node: lone Node,
    evidence: lone Evidence,
    restrictions: set RestrictionRef,
    labels: set Label
}

sig Request {
    actor: one Principal,
    purpose: one Purpose,
    order: one Order,
    inputs: seq Input,
    refs: Delegation -> Decision,           -- the operation-delegation references, with their decisions
    added: set RestrictionRef,              -- restrictions added to the join itself
    now: one Time,
    resolver: lone (Deny + Indeterminate)   -- what the extended configuration's resolver says, if anything
}

fun ins[r: Request]: set Input { r.inputs.elems }
fun ev[r: Request]: set Evidence { ins[r].evidence }
fun sources[r: Request]: set Delegation { ev[r].grant }
fun syn[r: Request]: set Delegation { r.refs.Decision & covers.Synthesize }
fun restrictionsOf[r: Request]: set RestrictionRef { ins[r].restrictions + r.added }

-- What can be wrong with a join, by the precedence rule it falls under.
abstract sig Fault {}
abstract sig Forbidding, Unresolvable, Invalid, Unknown extends Fault {}
one sig ForbidsThePurpose extends Forbidding {}
one sig RestrictionUnresolved extends Unresolvable {}
one sig NoSynthesisDelegation, UntrustedIssuer, NotTheActor, OtherPurpose, Expired, Revoked, OtherInputs,
    IssuanceDenied, InputDenied, SourcePurposeExcluded, SourceInactive extends Invalid {}
one sig TwoIssuances, NoIssuance, OtherIssuance, IssuanceNotCanonical, IssuanceIndeterminate,
    VersionsDisagree, ExpiriesDisagree, OtherDigest, UnregisteredDigest, NoEvidence, NoDecision,
    InputIndeterminate, DecisionForAnotherRequest, EvidenceOfAnotherInput, NoProvenance,
    ProvenanceMismatch, ProvenanceBroken extends Unknown {}

fun faults[r: Request]: set Fault {
    { f: Fault |
        (f = ForbidsThePurpose and some restrictionsOf[r].resolves & forbids.(r.purpose)) or
        (f = RestrictionUnresolved and some x: restrictionsOf[r] | no x.resolves) or
        -- Known invalid authority.
        (f = NoSynthesisDelegation and no syn[r]) or
        (f = UntrustedIssuer and some syn[r].issuer - Registry.trusted) or
        (f = NotTheActor and some syn[r].delegate - r.actor) or
        (f = OtherPurpose and some d: syn[r] | r.purpose not in d.purposes) or
        (f = Expired and some d: syn[r] | T/lte[d.expires, r.now]) or            -- now < expiry (J14)
        (f = Revoked and some syn[r].revoked) or
        (f = OtherInputs and some d: syn[r] |
            (r.order = InOrder and r.inputs.artifact != d.commits) or
            (r.order = AnyOrder and ins[r].artifact != d.commits.elems)) or
        (f = IssuanceDenied and Deny in syn[r].issuance.result) or
        (f = InputDenied and Deny in ev[r].decision.result) or
        (f = SourcePurposeExcluded and SourcePurposesConstrain in Deployment.settings and
            some g: sources[r] | r.purpose not in g.purposes) or
        (f = SourceInactive and ActiveAtEachStage in Deployment.settings and
            some g: sources[r] | some g.revoked or T/lte[g.expires, r.now]) or
        -- Missing, dangling, inconsistent or ambiguous records.
        (f = TwoIssuances and some d: syn[r] | not lone d.(r.refs)) or
        (f = NoIssuance and some d: syn[r] | no d.issuance) or
        (f = OtherIssuance and some d: syn[r] | some d.issuance and d.(r.refs) != d.issuance) or
        (f = IssuanceNotCanonical and some d: syn[r] | some d.issuance and d.issuance.fingerprint != d.canonical) or
        (f = IssuanceIndeterminate and Indeterminate in syn[r].issuance.result) or
        (f = VersionsDisagree and some d: syn[r] | some d.issuance and d.issuance.version != d.version) or
        (f = ExpiriesDisagree and some d: syn[r] | d.expires != d.issuedExpires) or
        (f = OtherDigest and some d: syn[r], i: ins[r] |
            i.artifact in d.commits.elems and d.commitDigest[i.artifact] != i.digest) or
        (f = UnregisteredDigest and some i: ins[r] | i.digest != i.artifact.registered) or
        (f = NoEvidence and some i: ins[r] | no i.evidence) or
        (f = NoDecision and some e: ev[r] | no e.decision) or
        (f = InputIndeterminate and Indeterminate in ev[r].decision.result) or
        (f = DecisionForAnotherRequest and some e: ev[r] | some e.decision and e.decision.fingerprint != e.acquisition) or
        (f = EvidenceOfAnotherInput and some i: ins[r] |
            some i.evidence and (i.evidence.boundArtifact != i.artifact or i.evidence.boundDigest != i.digest)) or
        (f = NoProvenance and some i: ins[r] | no i.node or i.node not in Registry.nodes) or
        (f = ProvenanceMismatch and some i: ins[r] |
            some i.node and (i.node.nArtifact != i.artifact or i.node.nDigest != i.digest)) or
        (f = ProvenanceBroken and some i: ins[r] |
            some i.node and (i.node in i.node.^up or some i.node.^up - Registry.nodes))
    }
}

-- 06 §3's precedence over the faults; the extended configuration's resolver can add an invalid or an
-- unknown authority, nothing else.
fun decide[r: Request, c: Config, fs: set Fault]: one Outcome {
    some fs & Forbidding => Deny
    else some fs & Unresolvable => Indeterminate
    else (some fs & Invalid or (c = Extended and r.resolver = Deny)) => Deny
    else (some fs & Unknown or (c = Extended and r.resolver = Indeterminate)) => Indeterminate
    else Permit
}

fun outcome[r: Request, c: Config]: one Outcome { decide[r, c, faults[r]] }

-- Release is its own decision (06 §8.6): the output carries every input's labels, and the recipient
-- needs a delegation that covers release and clears them.
fun outputLabels[r: Request]: set Label { ins[r].labels }
sig Disclosure { of: one Request, recipient: one Principal, cleared: set Label, rgrant: lone Delegation }
fun released[x: Disclosure]: one Outcome {
    (outcome[x.of, Default] = Permit and outputLabels[x.of] in x.cleared and
        some g: x.rgrant | Release in g.covers and g.delegate = x.recipient and no g.revoked and T/lt[x.of.now, g.expires])
    => Permit else Deny
}

/*
 * The scenarios. Each is the default deployment with exactly the row's faults.
 */

pred only[r: Request, fs: set Fault] { Deployment.settings = Setting and faults[r] = fs }
pred twoInputs[r: Request] { #ins[r] = 2 and #r.inputs = 2 }

-- R01 / J01: every input and the synthesis delegation hold.
pred R01[r: Request] { twoInputs[r] and some syn[r] and only[r, none] }
-- R02 / J07: both inputs read under their grants, no synthesis delegation.
pred R02[r: Request] { twoInputs[r] and no r.refs and only[r, NoSynthesisDelegation] }
-- R03: the only delegation named is a source grant, which doesn't cover synthesis.
pred R03[r: Request] { twoInputs[r] and r.refs.Decision in sources[r] and some r.refs and only[r, NoSynthesisDelegation] }
-- R04: the record binds the delegation to another issuance decision than the one named.
pred R04[r: Request] { twoInputs[r] and only[r, OtherIssuance] }
-- R05: the issuance decision's fingerprint isn't the canonical issuance request's.
pred R05[r: Request] { twoInputs[r] and only[r, IssuanceNotCanonical] }
-- J03: an input's evidence is a Permit for another request.
pred J03[r: Request] { twoInputs[r] and only[r, DecisionForAnotherRequest] }
-- R06: the issuer isn't in the trusted registry.
pred R06[r: Request] { twoInputs[r] and only[r, UntrustedIssuer] }
-- R07: a third input, independently authorized, outside the commitment to A and B.
pred R07[r: Request] {
    #ins[r] = 3 and some d: syn[r] | #d.commits.elems = 2 and d.commits.elems in ins[r].artifact
    only[r, OtherInputs]
}
-- R08: B's digest isn't the commitment's.
pred R08[r: Request] { twoInputs[r] and only[r, OtherDigest] }
-- J04: an input's digest isn't the registered artifact's.
pred J04[r: Request] { twoInputs[r] and only[r, UnregisteredDigest] }
-- R10 / J06: an input's recorded decision is Deny.
pred R10[r: Request] { twoInputs[r] and only[r, InputDenied] }
-- J06: an input's recorded decision is Indeterminate.
pred J06[r: Request] { twoInputs[r] and only[r, InputIndeterminate] }
-- R11: an input's decision can't be found.
pred R11[r: Request] { twoInputs[r] and only[r, NoDecision] }
-- J02: an input has no access evidence at all.
pred J02[r: Request] { twoInputs[r] and only[r, NoEvidence] }
-- R12: issued under another policy version than the current one, records consistent: decided now.
pred R12[r: Request] { twoInputs[r] and some d: syn[r] | d.version != Registry.current and only[r, none] }
-- J12: the inputs' decisions were made under different policy versions: provenance, decided now.
pred J12[r: Request] { twoInputs[r] and #ev[r].decision.version = 2 and only[r, none] }
-- R13: expired before now; J14: expiring exactly now.
pred R13[r: Request] { twoInputs[r] and some d: syn[r] | T/lt[d.expires, r.now] and only[r, Expired] }
pred J14[r: Request] { twoInputs[r] and some d: syn[r] | d.expires = r.now and only[r, Expired] }
-- R14: the record is active, but its expiry isn't the issuance's.
pred R14[r: Request] { twoInputs[r] and only[r, ExpiriesDisagree] }
-- R15 / J09: an inherited restriction forbids the purpose.
pred R15[r: Request] { twoInputs[r] and no r.added and only[r, ForbidsThePurpose] }
-- R16 / J10: a restriction can't be resolved.
pred R16[r: Request] { twoInputs[r] and only[r, RestrictionUnresolved] }
-- R17: B's provenance points to another digest; J05: an input's provenance node isn't recorded.
pred R17[r: Request] { twoInputs[r] and only[r, ProvenanceMismatch] }
pred J05[r: Request] { twoInputs[r] and only[r, NoProvenance] }
-- R20: a source grant's purposes exclude the synthesis's (the default: they constrain).
pred R20[r: Request] { twoInputs[r] and only[r, SourcePurposeExcluded] }
-- R20, the other setting: the same, where source purposes don't constrain.
pred R20Unconstrained[r: Request] {
    twoInputs[r] and Deployment.settings = ActiveAtEachStage and faults[r] = none
    some g: sources[r] | r.purpose not in g.purposes
}
-- R21: the issuance decision is missing.
pred R21[r: Request] { twoInputs[r] and only[r, NoIssuance] }
-- R22: the issuance decision's policy version isn't the record's.
pred R22[r: Request] { twoInputs[r] and only[r, VersionsDisagree] }
-- R23 / J13: a source grant revoked after acquisition (the default: active at each stage).
pred R23[r: Request] { twoInputs[r] and some sources[r].revoked and only[r, SourceInactive] }
-- R23, the other setting: valid at acquisition.
pred R23AtAcquisition[r: Request] {
    twoInputs[r] and Deployment.settings = SourcePurposesConstrain and faults[r] = none and some sources[r].revoked
}
-- R24: the delegation named twice, with two decisions.
pred R24[r: Request] { twoInputs[r] and only[r, TwoIssuances + OtherIssuance] }
-- J08: the synthesis delegation's purpose doesn't cover the request.
pred J08[r: Request] { twoInputs[r] and only[r, OtherPurpose] }
-- J15: a restriction shared by both inputs counts once.
pred J15[r: Request] { twoInputs[r] and some x: RestrictionRef | x in Input.restrictions and ins[r] in restrictions.x and only[r, none] }
-- J17: an input's provenance has a cycle or a missing ancestor.
pred J17[r: Request] { twoInputs[r] and only[r, ProvenanceBroken] }
-- J18: one input's evidence is another input's.
pred J18[r: Request] {
    twoInputs[r] and some disj i, j: ins[r] | i.evidence = j.evidence
    only[r, EvidenceOfAnotherInput]
}

run R01Exists { some r: Request | R01[r] } for 3 but 3 seq expect 1
check R01Permit { all r: Request | R01[r] implies outcome[r, Default] = Permit } for 3 but 3 seq expect 0
run R02Exists { some r: Request | R02[r] } for 3 but 3 seq expect 1
check R02Deny { all r: Request | R02[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R03Exists { some r: Request | R03[r] } for 3 but 3 seq expect 1
check R03Deny { all r: Request | R03[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R04Exists { some r: Request | R04[r] } for 3 but 3 seq expect 1
check R04Indeterminate { all r: Request | R04[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R05Exists { some r: Request | R05[r] } for 3 but 3 seq expect 1
check R05Indeterminate { all r: Request | R05[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run J03Exists { some r: Request | J03[r] } for 3 but 3 seq expect 1
check J03Indeterminate { all r: Request | J03[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R06Exists { some r: Request | R06[r] } for 3 but 3 seq expect 1
check R06Deny { all r: Request | R06[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R07Exists { some r: Request | R07[r] } for 3 but 3 seq expect 1
check R07Deny { all r: Request | R07[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R08Exists { some r: Request | R08[r] } for 3 but 3 seq expect 1
check R08Indeterminate { all r: Request | R08[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run J04Exists { some r: Request | J04[r] } for 3 but 3 seq expect 1
check J04Indeterminate { all r: Request | J04[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R10Exists { some r: Request | R10[r] } for 3 but 3 seq expect 1
check R10Deny { all r: Request | R10[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run J06Exists { some r: Request | J06[r] } for 3 but 3 seq expect 1
check J06Indeterminate { all r: Request | J06[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R11Exists { some r: Request | R11[r] } for 3 but 3 seq expect 1
check R11Indeterminate { all r: Request | R11[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run J02Exists { some r: Request | J02[r] } for 3 but 3 seq expect 1
check J02Indeterminate { all r: Request | J02[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R12Exists { some r: Request | R12[r] } for 3 but 3 seq expect 1
check R12Permit { all r: Request | R12[r] implies outcome[r, Default] = Permit } for 3 but 3 seq expect 0
run J12Exists { some r: Request | J12[r] } for 3 but 3 seq expect 1
check J12Permit { all r: Request | J12[r] implies outcome[r, Default] = Permit } for 3 but 3 seq expect 0
run R13Exists { some r: Request | R13[r] } for 3 but 3 seq expect 1
check R13Deny { all r: Request | R13[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run J14Exists { some r: Request | J14[r] } for 3 but 3 seq expect 1
check J14Deny { all r: Request | J14[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R14Exists { some r: Request | R14[r] } for 3 but 3 seq expect 1
check R14Indeterminate { all r: Request | R14[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R15Exists { some r: Request | R15[r] } for 3 but 3 seq expect 1
check R15Deny { all r: Request | R15[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R16Exists { some r: Request | R16[r] } for 3 but 3 seq expect 1
check R16Indeterminate { all r: Request | R16[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R17Exists { some r: Request | R17[r] } for 3 but 3 seq expect 1
check R17Indeterminate { all r: Request | R17[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run J05Exists { some r: Request | J05[r] } for 3 but 3 seq expect 1
check J05Indeterminate { all r: Request | J05[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R20Exists { some r: Request | R20[r] } for 3 but 3 seq expect 1
check R20Deny { all r: Request | R20[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R20UnconstrainedExists { some r: Request | R20Unconstrained[r] } for 3 but 3 seq expect 1
check R20UnconstrainedPermit { all r: Request | R20Unconstrained[r] implies outcome[r, Default] = Permit } for 3 but 3 seq expect 0
run R21Exists { some r: Request | R21[r] } for 3 but 3 seq expect 1
check R21Indeterminate { all r: Request | R21[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R22Exists { some r: Request | R22[r] } for 3 but 3 seq expect 1
check R22Indeterminate { all r: Request | R22[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run R23Exists { some r: Request | R23[r] } for 3 but 3 seq expect 1
check R23Deny { all r: Request | R23[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run R23AtAcquisitionExists { some r: Request | R23AtAcquisition[r] } for 3 but 3 seq expect 1
check R23AtAcquisitionPermit { all r: Request | R23AtAcquisition[r] implies outcome[r, Default] = Permit } for 3 but 3 seq expect 0
run R24Exists { some r: Request | R24[r] } for 3 but 3 seq expect 1
check R24Indeterminate { all r: Request | R24[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run J08Exists { some r: Request | J08[r] } for 3 but 3 seq expect 1
check J08Deny { all r: Request | J08[r] implies outcome[r, Default] = Deny } for 3 but 3 seq expect 0
run J15Exists { some r: Request | J15[r] } for 3 but 3 seq expect 1
check J15Permit { all r: Request | J15[r] implies outcome[r, Default] = Permit } for 3 but 3 seq expect 0
run J17Exists { some r: Request | J17[r] } for 3 but 3 seq expect 1
check J17Indeterminate { all r: Request | J17[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0
run J18Exists { some r: Request | J18[r] } for 3 but 3 seq expect 1
check J18Indeterminate { all r: Request | J18[r] implies outcome[r, Default] = Indeterminate } for 3 but 3 seq expect 0

-- R09 (P2): the same inputs in reverse order, order declared irrelevant: the same outcome, Permit here.
pred sameBut[r1, r2: Request] {
    r1.actor = r2.actor and r1.purpose = r2.purpose and r1.order = r2.order and r1.refs = r2.refs
    r1.now = r2.now and r1.resolver = r2.resolver
}
run R09Exists {
    some disj r1, r2: Request | sameBut[r1, r2] and r1.added = r2.added and r1.order = AnyOrder and
        #r1.inputs = 2 and r2.inputs = 0 -> r1.inputs[1] + 1 -> r1.inputs[0] and
        r1.inputs != r2.inputs and outcome[r1, Default] = Permit and outcome[r2, Default] = Permit
} for 3 but 3 seq expect 1

-- J16: order declared, the inputs reordered: a distinct request, the commitment to the other order
-- doesn't permit it.
run J16Exists {
    some disj r1, r2: Request | sameBut[r1, r2] and r1.added = r2.added and r1.order = InOrder and
        #r1.inputs = 2 and r1.inputs.elems = r2.inputs.elems and r1.inputs != r2.inputs and
        outcome[r1, Default] = Permit and outcome[r2, Default] = Deny
} for 3 but 3 seq expect 1
assert OrderedJoinsAreTheirOrder {
    all r: Request, c: Config | (r.order = InOrder and outcome[r, c] = Permit) implies
        all d: syn[r] | d.commits = r.inputs.artifact
}
check OrderedJoinsAreTheirOrder for 3 but 3 seq expect 0

-- J11: a restriction added to an otherwise permitted join forbids it: Deny.
run J11Exists {
    some disj r1, r2: Request | sameBut[r1, r2] and r1.inputs = r2.inputs and no r1.added and
        some r2.added and outcome[r1, Default] = Permit and outcome[r2, Default] = Deny
} for 3 but 3 seq expect 1

-- R18: synthesized, but the output carries a label the recipient isn't cleared for: not released.
run R18Exists {
    some x: Disclosure | outcome[x.of, Default] = Permit and some outputLabels[x.of] - x.cleared and
        Release in x.rgrant.covers and released[x] = Deny
} for 3 but 3 seq expect 1
-- J19: synthesized, but no delegation covers the release: not released.
run J19Exists {
    some x: Disclosure | outcome[x.of, Default] = Permit and no x.rgrant and released[x] = Deny
} for 3 but 3 seq expect 1
-- A release is permitted only with its own delegation, and only of labels it clears.
assert ReleaseIsItsOwnDecision {
    all x: Disclosure | released[x] = Permit implies
        (some g: x.rgrant | Release in g.covers and g.delegate = x.recipient) and
        (all i: ins[x.of] | i.labels in x.cleared)
}
check ReleaseIsItsOwnDecision for 3 but 3 seq expect 0

/*
 * The properties, in both configurations.
 */

-- 06 §8.1: no cross-branch substitution. A permitted join's every input carries its own evidence: bound
-- to its artifact and digest, a Permit of its own acquisition request.
assert NoCrossBranchSubstitution {
    all r: Request, c: Config | outcome[r, c] = Permit implies all i: ins[r] {
        i.evidence.boundArtifact = i.artifact and i.evidence.boundDigest = i.digest
        i.evidence.decision.result = Permit and i.evidence.decision.fingerprint = i.evidence.acquisition
    }
}
check NoCrossBranchSubstitution for 3 but 3 seq expect 0

-- 06 §8.3: only a delegation that covers synthesis permits a join, never the input grants.
assert TheInputGrantsAreNotAuthority {
    all r: Request, c: Config | outcome[r, c] = Permit implies
        some d: r.refs.Decision | Synthesize in d.covers and d.issuance.result = Permit
}
check TheInputGrantsAreNotAuthority for 3 but 3 seq expect 0

-- P1: a missing or inconsistent record never ends in Permit.
assert IndeterminateNeverPermits {
    all r: Request, c: Config | some faults[r] & (Unknown + Unresolvable) implies outcome[r, c] != Permit
}
check IndeterminateNeverPermits for 3 but 3 seq expect 0

-- P2: where order is undeclared, the same inputs in any order decide the same.
assert OrderIndependent {
    all r1, r2: Request, c: Config |
        (sameBut[r1, r2] and r1.added = r2.added and r1.order = AnyOrder and r1.inputs.elems = r2.inputs.elems)
            implies outcome[r1, c] = outcome[r2, c]
}
check OrderIndependent for 3 but 3 seq expect 0

-- P3 (J11): adding restrictions, to the join or to an input, never turns a non-Permit into Permit.
assert RestrictionsOnlyNarrow {
    all r1, r2: Request, c: Config |
        (sameBut[r1, r2] and r1.inputs.(artifact + digest + node + evidence) = r2.inputs.(artifact + digest + node + evidence)
            and restrictionsOf[r1] in restrictionsOf[r2] and outcome[r1, c] != Permit)
            implies outcome[r2, c] != Permit
}
check RestrictionsOnlyNarrow for 3 but 3 seq expect 0

-- XP-16: the extended configuration's resolver only narrows: nothing it permits the default doesn't,
-- and nothing the default denies it doesn't.
assert TheResolverOnlyNarrows {
    all r: Request {
        outcome[r, Extended] = Permit implies outcome[r, Default] = Permit
        outcome[r, Default] = Deny implies outcome[r, Extended] = Deny
        no r.resolver implies outcome[r, Extended] = outcome[r, Default]
    }
}
check TheResolverOnlyNarrows for 3 but 3 seq expect 0
-- The resolver's answers are reached: an otherwise permitted join denied, and one undecided.
run TheResolverDenies { some r: Request | faults[r] = none and outcome[r, Extended] = Deny } for 3 but 3 seq expect 1
run TheResolverDoesntKnow { some r: Request | faults[r] = none and outcome[r, Extended] = Indeterminate } for 3 but 3 seq expect 1

/*
 * The broken variant, which must fail (spec/check.sh "violated"): the evidence an input carries is
 * accepted without checking it's bound to that input, so one input's stands in for another's.
 */
fun outcomeUnbound[r: Request]: one Outcome { decide[r, Default, faults[r] - EvidenceOfAnotherInput] }
assert BrokenUnboundEvidence {
    all r: Request | outcomeUnbound[r] = Permit implies
        all i: ins[r] | i.evidence.boundArtifact = i.artifact and i.evidence.boundDigest = i.digest
}
check BrokenUnboundEvidence for 3 but 3 seq expect 1
