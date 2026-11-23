#!/bin/sh
# Checks every formal model, in the spec container: docker compose run --rm spec ./check.sh
#
# Each check says what it must show. "holds" checks fail when the checker finds a counterexample;
# "violated" checks are deliberately broken variants (e.g. the naive token implementation) and fail
# when the checker does NOT find the counterexample, so a checker that silently stopped working is
# caught too.
set -u

failed=0
out=$(mktemp)

holds() {
	name=$1
	shift
	if "$@" >"$out" 2>&1; then
		echo "ok    $name"
	else
		echo "FAIL  $name: expected to hold"
		cat "$out"
		failed=1
	fi
}

violated() {
	name=$1
	pattern=$2
	shift 2
	if "$@" >"$out" 2>&1; then
		echo "FAIL  $name: expected a counterexample, the checker found none"
		cat "$out"
		failed=1
	elif grep -q "$pattern" "$out"; then
		echo "ok    $name: counterexample found, as expected"
	else
		echo "FAIL  $name: failed, but not with the expected counterexample"
		cat "$out"
		failed=1
	fi
}

alloy() { java -jar /opt/alloy/alloy.jar exec -q -c '*' -o /tmp/alloy -f -t json "$@"; }

# Decisions: the precedence table (Alloy; its own "expect" annotations are checked by exec).
holds "decisions/precedence.als (Alloy)" alloy decisions/precedence.als
# Delegation and attenuation (M3-1): I3 on every dimension, the root re-evaluated now, nothing
# non-delegable passed on; its broken variants (no root check, id narrowing reversed) are expected
# counterexamples within the file ("expect 1").
holds "delegation/attenuation.als (Alloy)" alloy delegation/attenuation.als
# The PHP check of one link (yorvoid/delegation's Attenuation) replays the model's sample of links; regenerate
# it with "node delegation/fixtures.mjs > delegation/fixtures.json".
holds "delegation: the links the PHP tests check are the model's" sh -c 'node delegation/fixtures.mjs | cmp -s - delegation/fixtures.json'
# Joins (M3-1): each of R01-R24 and J01-J20 (but R19 and J20, the guard's) exists and has its outcome;
# no cross-branch substitution, the input grants never authority, P1-P3, in both configurations
# (XP-16); evidence accepted unbound, input grants taken as synthesis authority and the join's added
# restrictions ignored are the expected counterexamples ("expect 1").
holds "joins/joins.als (Alloy)" alloy joins/joins.als

# Delegation chains over time (M3-1), in Quint: no use is accepted while a link is revoked or expired or
# the root lost the authority. "versioned" is YorvoID's cache (every link's version, the authority's
# version, the chain's earliest expiry) and must be safe; NO_REUSE is a witness that its entries answer,
# so that isn't by definition. Three broken caches must break it: no versions, no expiry check, the
# leaf's version only (an ancestor's revocation missed). "checked" recomputes every time: the reference
# M3-2 replays. Apalache for 5 steps (the leaf-only miss takes 5), simulation for 25.
holds "delegation chains: typecheck (Quint)" quint typecheck delegation/chains.qnt
holds "delegation chains: the versioned cache never accepts an invalid chain (Quint, Apalache)" quint verify --main=versioned --invariant=SAFETY --max-steps=5 delegation/chains.qnt
violated "delegation chains: a cache without versions accepts a revoked chain (Quint, Apalache)" "\[violation\]" quint verify --main=unversioned --invariant=SAFE --max-steps=5 delegation/chains.qnt
violated "delegation chains: versions without the expiry outlive it (Quint, Apalache)" "\[violation\]" quint verify --main=noExpiry --invariant=SAFE --max-steps=5 delegation/chains.qnt
violated "delegation chains: the leaf's version alone misses an ancestor's revocation (Quint, Apalache)" "\[violation\]" quint verify --main=leafOnly --invariant=SAFE --max-steps=5 delegation/chains.qnt
holds "delegation chains: the versioned cache never accepts an invalid chain (Quint, simulation)" quint run --main=versioned --invariant=SAFETY --max-samples=20000 --max-steps=25 --seed=42 delegation/chains.qnt
violated "delegation chains: the versioned cache's entries answer uses (Quint, simulation)" "\[violation\]" quint run --main=versioned --invariant=NO_REUSE --max-samples=20000 --max-steps=25 --seed=42 delegation/chains.qnt
violated "delegation chains: a use under a chain of three is reached (Quint, simulation)" "\[violation\]" quint run --main=versioned --invariant=NO_DEEP_USE --max-samples=50000 --max-steps=25 --seed=42 delegation/chains.qnt
holds "delegation chains: recomputing every time is safe and well-formed (Quint, simulation)" quint run --main=checked --invariant=SAFETY --max-samples=20000 --max-steps=25 --seed=42 delegation/chains.qnt
for broken in unversioned noExpiry leafOnly; do
	violated "delegation chains: $broken breaks SAFE (Quint, simulation)" "\[violation\]" quint run --main=$broken --invariant=SAFE --max-samples=20000 --max-steps=25 --seed=42 delegation/chains.qnt
done
# The executions the PHP tests replay (ChainTracesTest): "node traces.mjs delegation/chains.qnt checked 100 14 0xc4a1d5 > delegation/traces.itf.json".
TRACES_CHAINS='node traces.mjs delegation/chains.qnt checked 100 14 0xc4a1d5'
holds "delegation chains: the executions to replay are the model's" sh -c "$TRACES_CHAINS | cmp -s - delegation/traces.itf.json"

# The guard (M3-1), in Quint: I5 (no guarded effect commits on revoked authority) and P4 (a single-use
# permit consumed once, by its effect), under READ COMMITTED and REPEATABLE READ. Broken: reading before
# the lock; a lock that doesn't write, under REPEATABLE READ (safe under READ COMMITTED, as checked); a
# revoker that doesn't take the lock; consuming without a compare-and-swap; a permit not bound to its
# effect. The witnesses show an effect, a revocation and a conflict.
holds "guard: typecheck (Quint)" quint typecheck guard/guard.qnt
for main in guardRc guardRr readLockRc; do
	holds "guard: $main is safe (Quint, Apalache)" quint verify --main=$main --invariant=SAFETY --max-steps=10 guard/guard.qnt
	holds "guard: $main is safe (Quint, simulation)" quint run --main=$main --invariant=SAFETY --max-samples=20000 --max-steps=20 --seed=42 guard/guard.qnt
done
violated "guard: reading before the lock commits on revoked authority (Quint, Apalache)" "\[violation\]" quint verify --main=checkThenLockRc --invariant=I5 --max-steps=10 guard/guard.qnt
violated "guard: a lock that doesn't write, under REPEATABLE READ, commits on revoked authority (Quint, Apalache)" "\[violation\]" quint verify --main=readLockRr --invariant=I5 --max-steps=10 guard/guard.qnt
for isolation in Rc Rr; do
	violated "guard: a revoker without the lock lets an effect commit on revoked authority ($isolation, Quint, Apalache)" "\[violation\]" quint verify --main=revokeNoLock$isolation --invariant=I5 --max-steps=10 guard/guard.qnt
done
violated "guard: consuming without a compare-and-swap uses the permit twice (Quint, Apalache)" "\[violation\]" quint verify --main=noCasRc --invariant=P4 --max-steps=10 guard/guard.qnt
violated "guard: a permit not bound to its effect is used by another (Quint, Apalache)" "\[violation\]" quint verify --main=noBindingRc --invariant=P4 --max-steps=10 guard/guard.qnt
for witness in NO_EFFECT NO_REVOCATION NO_CONFLICT; do
	violated "guard: $witness is reached (Quint, simulation)" "\[violation\]" quint run --main=guardRr --invariant=$witness --max-samples=20000 --max-steps=20 --seed=42 guard/guard.qnt
done
# The executions M3-3's PHP tests will replay, under both isolation levels:
# "node traces.mjs guard/guard.qnt guardRc 100 14 0x9a4d0 > guard/traces-rc.itf.json" (and guardRr, traces-rr).
holds "guard: the executions to replay are the model's (READ COMMITTED)" sh -c "node traces.mjs guard/guard.qnt guardRc 100 14 0x9a4d0 | cmp -s - guard/traces-rc.itf.json"
holds "guard: the executions to replay are the model's (REPEATABLE READ)" sh -c "node traces.mjs guard/guard.qnt guardRr 100 14 0x9a4d0 | cmp -s - guard/traces-rr.itf.json"

# Budgets along a delegation chain (M3-1), in Quint: no delegation's uses or reservations exceed its
# limit, a use is refused only when its leaf has no room, and an ended delegation gives back what it
# didn't consume. "reserve" is YorvoID's: a limit reserved from the delegator when issued, a use charged
# to its leaf in one compare-and-swap. Broken: checking then charging, issuing without reserving,
# charging the whole chain, keeping an ended reservation whole. The witnesses show a use, a refusal, a
# return and a use at depth 3. Apalache checks the safe mode for 6 steps (enough to issue two delegations
# and race two uses for the last unit; 8 takes over an hour), the broken ones for 8 (the longest, issuing
# without reserving, takes 8); simulation for 20.
holds "budgets: typecheck (Quint)" quint typecheck budgets/budgets.qnt
holds "budgets: reserving is safe (Quint, Apalache)" quint verify --main=reserve --invariant=SAFETY --max-steps=6 budgets/budgets.qnt
violated "budgets: checking then charging overspends (Quint, Apalache)" "\[violation\]" quint verify --main=checkThenCharge --invariant=NO_OVERSPEND --max-steps=8 budgets/budgets.qnt
violated "budgets: issuing without reserving overspends (Quint, Apalache)" "\[violation\]" quint verify --main=noReservation --invariant=NO_OVERSPEND --max-steps=8 budgets/budgets.qnt
violated "budgets: charging the whole chain refuses a use with room (Quint, Apalache)" "\[violation\]" quint verify --main=chargeChain --invariant=NO_FALSE_REFUSAL --max-steps=8 budgets/budgets.qnt
violated "budgets: an ended reservation kept whole isn't given back (Quint, Apalache)" "\[violation\]" quint verify --main=noReturn --invariant=RETURNED --max-steps=8 budgets/budgets.qnt
holds "budgets: reserving is safe (Quint, simulation)" quint run --main=reserve --invariant=SAFETY --max-samples=20000 --max-steps=20 --seed=42 budgets/budgets.qnt
for broken in "checkThenCharge NO_OVERSPEND" "noReservation NO_OVERSPEND" "chargeChain NO_FALSE_REFUSAL" "noReturn RETURNED"; do
	set -- $broken
	violated "budgets: $1 breaks $2 (Quint, simulation)" "\[violation\]" quint run --main=$1 --invariant=$2 --max-samples=20000 --max-steps=20 --seed=42 budgets/budgets.qnt
done
for witness in NO_USE NO_REFUSAL NO_RETURN NO_DEEP_USE; do
	violated "budgets: $witness is reached (Quint, simulation)" "\[violation\]" quint run --main=reserve --invariant=$witness --max-samples=20000 --max-steps=20 --seed=42 budgets/budgets.qnt
done
# The executions M3-3's PHP tests will replay: "node traces.mjs budgets/budgets.qnt reserve 100 14 0xb6d9e7 > budgets/traces.itf.json".
holds "budgets: the executions to replay are the model's" sh -c "node traces.mjs budgets/budgets.qnt reserve 100 14 0xb6d9e7 | cmp -s - budgets/traces.itf.json"
# The requests that tests/Core/Authorization/PrecedenceFixturesTest.php decides with the PHP evaluator
# must be the model's: regenerate them with "node decisions/fixtures.mjs > decisions/fixtures.json".
holds "decisions: the fixtures the PHP tests decide are the model's (default configuration)" sh -c 'node decisions/fixtures.mjs | cmp -s - decisions/fixtures.json'
# The extended configuration (XP-16): every M2 extension point engaged; "node decisions/fixtures.mjs
# extended > decisions/fixtures-extended.json". The default's are unchanged by it (MP-1, DefaultIsTheTable).
holds "decisions: the fixtures the PHP tests decide are the model's (extended configuration)" sh -c 'node decisions/fixtures.mjs extended | cmp -s - decisions/fixtures-extended.json'
# Ordered conditions (XP-15) in both configurations; "node decisions/fixtures.mjs ordered >
# decisions/fixtures-ordered.json".
holds "decisions: the fixtures the PHP tests decide are the model's (ordered conditions)" sh -c 'node decisions/fixtures.mjs ordered | cmp -s - decisions/fixtures-ordered.json'
# Beside them, a seeded sample of 10000 requests of 3 and 4 elements, which the model decides one by one
# (enumerating 4 would be about 1.4M); "node decisions/ordered-sample.mjs > decisions/fixtures-ordered-sample.json".
holds "decisions: the sample of larger ordered requests is the model's" sh -c 'node decisions/ordered-sample.mjs | cmp -s - decisions/fixtures-ordered-sample.json'
# MP-1: the default configuration's fixtures are the enterprise behaviour, so they don't change when an
# extension point is added. A change to them is a change of the default, and needs this hash changed too,
# in review.
holds "decisions: the default configuration's fixtures are unchanged (MP-1)" sh -c 'echo "818929b131d3830eb4911f8cf9b341779bda21ccc58a7c79dc2735f37d45ec4c  decisions/fixtures.json" | sha256sum -c -'

# One-time tokens, in Quint: Apalache checks every execution up to the bound (10 steps, beyond the
# model's longest execution of 9 steps), and simulation runs random executions.
holds "tokens: typecheck (Quint)" quint typecheck tokens/one_time_token.qnt
holds "tokens: atomic consumption is safe (Quint, Apalache)" quint verify --main=atomic --invariant=SAFETY --max-steps=10 tokens/one_time_token.qnt
violated "tokens: naive consumption uses a token twice (Quint, Apalache)" "\[violation\]" quint verify --main=naive --invariant=AT_MOST_ONCE --max-steps=10 tokens/one_time_token.qnt
holds "tokens: atomic consumption is safe (Quint, simulation)" quint run --main=atomic --invariant=SAFETY --max-samples=20000 --max-steps=20 --seed=42 tokens/one_time_token.qnt
violated "tokens: naive consumption uses a token twice (Quint, simulation)" "\[violation\]" quint run --main=naive --invariant=AT_MOST_ONCE --max-samples=20000 --max-steps=20 --seed=42 tokens/one_time_token.qnt

# The executions that tests/Authn/Token/OneTimeTokenTracesTest.php replays against the PHP code must be
# the model's: regenerate them with the command in TRACES_TOKENS after changing it.
TRACES_TOKENS='node traces.mjs tokens/one_time_token.qnt atomic 100 12 0x5e171e1'
holds "tokens: the executions the PHP tests replay are the model's" sh -c "$TRACES_TOKENS | cmp -s - tokens/traces.itf.json"

# The intent verifier's keys, in Quint: never without keys, nothing added before its delay. The variant
# that counts only the current keys, not those already leaving, must lose them all.
holds "verifier keys: typecheck (Quint)" quint typecheck verifier/key_registry.qnt
holds "verifier keys: safe (Quint, Apalache)" quint verify --main=counted --invariant=SAFETY --max-steps=10 verifier/key_registry.qnt
violated "verifier keys: counting only current keys loses them all (Quint, Apalache)" "\[violation\]" quint verify --main=uncounted --invariant=NEVER_WITHOUT_KEYS --max-steps=10 verifier/key_registry.qnt
holds "verifier keys: safe (Quint, simulation)" quint run --main=counted --invariant=SAFETY --max-samples=20000 --max-steps=20 --seed=42 verifier/key_registry.qnt
holds "verifier keys: safe with the replayed clock (Quint, simulation)" quint run --main=replayed --invariant=SAFETY --max-samples=20000 --max-steps=30 --seed=42 verifier/key_registry.qnt
# What tests/IntentVerifier/KeyRegistryTracesTest.php replays against KeyRegistry.
TRACES_KEYS='node traces.mjs verifier/key_registry.qnt replayed 60 24 0x6b657973'
holds "verifier keys: the executions the PHP tests replay are the model's" sh -c "$TRACES_KEYS | cmp -s - verifier/traces.itf.json"

# Claiming a realm with its setup code, in Quint: one owner, whoever comes at the same moment, and the
# realm's first principal, also when an invitation is accepted at the same moment. Marking it claimed
# without a compare-and-swap must give it two; checking without the realm's lock, an owner after someone.
holds "setup claim: typecheck (Quint)" quint typecheck setup/setup_claim.qnt
holds "setup claim: one owner, the first principal (Quint, Apalache)" quint verify --main=atomic --invariant=SAFETY --max-steps=12 setup/setup_claim.qnt
violated "setup claim: without a compare-and-swap, two owners (Quint, Apalache)" "\[violation\]" quint verify --main=naive --invariant=ONE_OWNER --max-steps=12 setup/setup_claim.qnt
violated "setup claim: without the realm's lock, an owner after someone (Quint, Apalache)" "\[violation\]" quint verify --main=unlocked --invariant=OWNER_FIRST --max-steps=12 setup/setup_claim.qnt
holds "setup claim: one owner, the first principal (Quint, simulation)" quint run --main=atomic --invariant=SAFETY --max-samples=20000 --max-steps=20 --seed=42 setup/setup_claim.qnt
# What tests/Authn/Setup/SetupClaimTracesTest.php replays against SetupCodes: only LOCKED = true, YorvoID's.
TRACES_SETUP='node traces.mjs setup/setup_claim.qnt atomic 100 14 0x5e7c1a1'
holds "setup claim: the executions the PHP tests replay are the model's" sh -c "$TRACES_SETUP | cmp -s - setup/traces.itf.json"

# An offline limit (XP-14), in Quint: measured by elapsed time it never permits past the limit, whatever
# the wall clock and reboots do; measured by the wall clock it does, and only when that's set back.
holds "offline limit: typecheck (Quint)" quint typecheck offline/elapsed.qnt
holds "offline limit: elapsed time never permits past it (Quint, Apalache)" quint verify --main=elapsedLimit --invariant=SAFE --max-steps=12 offline/elapsed.qnt
# The counterexample is the wall clock set back (its states are printed at verbosity 3), and the next
# check shows that every one is.
violated "offline limit: the wall clock permits past it, set back (Quint, Apalache)" "offlineLimit::setBack: true" quint verify --main=wallLimit --invariant=SAFE --max-steps=12 --verbosity=3 offline/elapsed.qnt
holds "offline limit: the wall clock breaks it only when set back (Quint, Apalache)" quint verify --main=wallLimit --invariant=WALL_SAFE_UNLESS_SET_BACK --max-steps=12 offline/elapsed.qnt
holds "offline limit: elapsed time never permits past it (Quint, simulation)" quint run --main=elapsedLimit --invariant=SAFE --max-samples=20000 --max-steps=20 --seed=42 offline/elapsed.qnt
# What tests/Core/Authorization/OfflineLimitTracesTest.php replays against WithinOfflineLimit.
TRACES_OFFLINE='node traces.mjs offline/elapsed.qnt elapsedLimit 80 16 0x0ff11e'
holds "offline limit: the executions the PHP tests replay are the model's" sh -c "$TRACES_OFFLINE | cmp -s - offline/traces.itf.json"

# Apalache writes its working files next to the models; they aren't needed afterwards.
rm -rf "$out" _apalache-out
rmdir tmp 2>/dev/null
exit $failed
