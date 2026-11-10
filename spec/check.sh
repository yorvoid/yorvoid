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
# The requests that tests/Core/Authorization/PrecedenceFixturesTest.php decides with the PHP evaluator
# must be the model's: regenerate them with "node decisions/fixtures.mjs > decisions/fixtures.json".
holds "decisions: the fixtures the PHP tests decide are the model's (default configuration)" sh -c 'node decisions/fixtures.mjs | cmp -s - decisions/fixtures.json'
# The extended configuration (XP-16): every M2 extension point engaged; "node decisions/fixtures.mjs
# extended > decisions/fixtures-extended.json". The default's are unchanged by it (MP-1, DefaultIsTheTable).
holds "decisions: the fixtures the PHP tests decide are the model's (extended configuration)" sh -c 'node decisions/fixtures.mjs extended | cmp -s - decisions/fixtures-extended.json'
# Ordered conditions (XP-15) in both configurations; "node decisions/fixtures.mjs ordered >
# decisions/fixtures-ordered.json".
holds "decisions: the fixtures the PHP tests decide are the model's (ordered conditions)" sh -c 'node decisions/fixtures.mjs ordered | cmp -s - decisions/fixtures-ordered.json'
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
# without a compare-and-swap must give it two; checking without the log's lock, an owner after someone.
holds "setup claim: typecheck (Quint)" quint typecheck setup/setup_claim.qnt
holds "setup claim: one owner, the first principal (Quint, Apalache)" quint verify --main=atomic --invariant=SAFETY --max-steps=12 setup/setup_claim.qnt
violated "setup claim: without a compare-and-swap, two owners (Quint, Apalache)" "\[violation\]" quint verify --main=naive --invariant=ONE_OWNER --max-steps=12 setup/setup_claim.qnt
violated "setup claim: without the log's lock, an owner after someone (Quint, Apalache)" "\[violation\]" quint verify --main=unlocked --invariant=OWNER_FIRST --max-steps=12 setup/setup_claim.qnt
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
