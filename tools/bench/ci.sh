#!/bin/sh
# The benchmarks in CI (M2-8), in the php container:
#
#   sh tools/bench/ci.sh pr <base>   a pull request: the base and the head on this runner, subject by
#                                    subject in turns, base, head, head, base (ABBA, C2), so that the
#                                    runner's drift falls on both alike; then compared
#   sh tools/bench/ci.sh main [<tag>] a push to main: the head's table, and, against the last release's
#                                    tag if it has benchmarks, the change since, which gates nothing
#   sh tools/bench/ci.sh aa          the gate's false failures: the head against itself, in the same
#                                    turns (C1)
#
# Each checkout runs its own benchmark code. The tables are Markdown, written to $GITHUB_STEP_SUMMARY
# when it's set, and the runs' dumps stay in var/bench for the job's artifact.
set -eu

# The container has no home to write git's configuration to: the checkout is trusted for this run.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'

root=$(cd "$(dirname "$0")/../.." && pwd)
out="$root/var/bench"
summary=${GITHUB_STEP_SUMMARY:-/dev/stdout}
mkdir -p "$out"

ROUND=100

# Runs a checkout's benchmarks, or one subject of them, into a dump; a checkout without it writes an
# empty one.
run() {
	dir=$1
	dump=$2
	filter=${3:-}

	if [ ! -f "$dir/tools/bench/run.php" ] || { [ -n "$filter" ] && ! grep -qs "function ${filter#*::}(" "$dir/tools/bench/${filter%%::*}.php"; }; then
		echo '{"subjects": {}}' >"$dump"
		return
	fi

	php "$root/tools/bench/settle.php"
	(cd "$dir" && php -d opcache.enable_cli=1 -d pcov.enabled=0 tools/bench/run.php --out="$dump" ${filter:+--filter="$filter" --iterations=$ROUND} 2>/dev/null)
}

# The head's subjects, as Class::subject.
subjects() {
	for file in "$root"/tools/bench/*Bench.php; do
		class=$(basename "$file" .php)
		grep -o 'function bench[A-Za-z0-9]*' "$file" | sed "s/function /$class::/"
	done
}

# Base and head, each subject in turns (ABBA): their dumps, pooled per side, in $out/a.list and $out/b.list.
turns() {
	a=$1
	b=$2
	: >"$out/a.list"
	: >"$out/b.list"

	for subject in $(subjects); do
		name=$(echo "$subject" | tr ':' '_')
		run "$a" "$out/$name-a1.json" "$subject"
		run "$b" "$out/$name-b1.json" "$subject"
		run "$b" "$out/$name-b2.json" "$subject"
		run "$a" "$out/$name-a2.json" "$subject"
		printf '%s,%s,' "$out/$name-a1.json" "$out/$name-a2.json" >>"$out/a.list"
		printf '%s,%s,' "$out/$name-b1.json" "$out/$name-b2.json" >>"$out/b.list"
	done
}

# A checkout of the commit, with its own dependencies.
checkout() {
	dir="$out/$1"
	rm -rf "$dir"
	git -C "$root" worktree prune
	git -C "$root" worktree add --detach "$dir" "$2" >/dev/null
	(cd "$dir" && composer install --no-interaction --no-progress --quiet)
	echo "$dir"
}

# The subjects whose benchmark code differs between the two commits (C3): a benchmark's own file
# changes its subjects; anything else they're built from changes them all.
changed() {
	git -C "$root" diff --name-only "$1" "$2" -- tools/bench tests/Support tests/Policy/Fixtures tests/Relations/Fixtures tests/Symfony/App composer.lock | while read -r file; do
		case "$file" in
			tools/bench/*Bench.php) basename "$file" .php | sed 's/$/::*/' ;;
			*) echo '*' ;;
		esac
	done >"$out/changed.txt"
}

compare() {
	php "$root/tools/bench/bench.php" compare "$1" "$2" "$out/changed.txt" >>"$summary"
}

case "${1:-}" in
	pr)
		base=$(checkout base "$2")
		changed "$2" HEAD
		echo "## Benchmarks: the head against the base ($(git -C "$root" rev-parse --short "$2"))" >>"$summary"
		turns "$base" "$root"
		compare "$(cat "$out/a.list")" "$(cat "$out/b.list")"
		;;
	main)
		echo '## Benchmarks' >>"$summary"
		run "$root" "$out/head.json"
		php "$root/tools/bench/bench.php" report "$out/head.json" >>"$summary"
		tag=${2:-$(git -C "$root" describe --tags --abbrev=0 2>/dev/null || true)}

		if [ -n "$tag" ]; then
			release=$(checkout release "$tag")
			changed "$tag" HEAD
			echo '' >>"$summary"
			echo "### Since $tag (gates nothing)" >>"$summary"
			turns "$release" "$root"
			compare "$(cat "$out/a.list")" "$(cat "$out/b.list")" || true
		fi
		;;
	aa)
		: >"$out/changed.txt"
		echo '## Benchmarks: the head against itself' >>"$summary"
		turns "$root" "$root"
		status=0
		compare "$(cat "$out/a.list")" "$(cat "$out/b.list")" || status=1
		echo "failed=$status" >"$out/aa.txt"
		exit $status
		;;
	*)
		echo 'Usage: sh tools/bench/ci.sh pr <base> | main [<tag>] | aa' >&2
		exit 2
		;;
esac
