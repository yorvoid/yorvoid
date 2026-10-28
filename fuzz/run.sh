#!/bin/sh
# Fuzzes every target: composer fuzz [runs per target, 20000 by default].
# FUZZ_SKIP lists targets to leave out, separated by spaces, e.g. FUZZ_SKIP="tokens realm".
#
# Each target runs in var/fuzz/<target>/, where php-fuzzer keeps its corpus and writes crash-*.txt
# for every input that broke a rule; any crash fails the run. Reproduce one with:
#   vendor/bin/php-fuzzer run-single fuzz/<target>.php var/fuzz/<target>/crash-….txt
set -u

runs=${1:-20000}
root=$(pwd)
failed=0

for target in fuzz/*.php; do
	name=$(basename "$target" .php)
	[ "$name" = bootstrap ] && continue

	case " ${FUZZ_SKIP:-} " in
	*" $name "*)
		echo "skip  $name"
		continue
		;;
	esac

	dir="var/fuzz/$name"
	mkdir -p "$dir/corpus"
	rm -f "$dir"/crash-*.txt

	(cd "$dir" && php "$root/vendor/bin/php-fuzzer" fuzz --max-runs "$runs" "$root/$target" corpus >log.txt 2>&1)
	status=$?

	if [ $status -ne 0 ] || ls "$dir"/crash-*.txt >/dev/null 2>&1; then
		echo "FAIL  $name: see $dir/log.txt and $dir/crash-*.txt"
		failed=1
	else
		echo "ok    $name ($runs runs)"
	fi
done

exit $failed
