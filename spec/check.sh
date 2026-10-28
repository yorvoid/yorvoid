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

# One-time tokens, in Quint: Apalache checks every execution up to the bound (10 steps, beyond the
# model's longest execution of 9 steps), and simulation runs random executions.
holds "tokens: typecheck (Quint)" quint typecheck tokens/one_time_token.qnt
holds "tokens: atomic consumption is safe (Quint, Apalache)" quint verify --main=atomic --invariant=SAFETY --max-steps=10 tokens/one_time_token.qnt
violated "tokens: naive consumption uses a token twice (Quint, Apalache)" "\[violation\]" quint verify --main=naive --invariant=AT_MOST_ONCE --max-steps=10 tokens/one_time_token.qnt
holds "tokens: atomic consumption is safe (Quint, simulation)" quint run --main=atomic --invariant=SAFETY --max-samples=20000 --max-steps=20 --seed=42 tokens/one_time_token.qnt
violated "tokens: naive consumption uses a token twice (Quint, simulation)" "\[violation\]" quint run --main=naive --invariant=AT_MOST_ONCE --max-samples=20000 --max-steps=20 --seed=42 tokens/one_time_token.qnt

# The executions that tests/Authn/Token/OneTimeTokenTracesTest.php replays against the PHP code must be
# the model's: regenerate them with "node tokens/traces.mjs > tokens/traces.json" after changing it.
holds "tokens: the executions the PHP tests replay are the model's" sh -c 'node tokens/traces.mjs | cmp -s - tokens/traces.json'

# Apalache writes its working files next to the models; they aren't needed afterwards.
rm -rf "$out" _apalache-out
rmdir tmp 2>/dev/null
exit $failed
