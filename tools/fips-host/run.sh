#!/bin/bash
# The FIPS-enabled operating system run before a release (CRY-01), on a throwaway AlmaLinux 9 host
# (e.g. a cloud VM) reachable as root over SSH with a key: switches it to FIPS mode
# (fips-mode-setup --enable, then a reboot), installs PHP 8.4 against the system's OpenSSL, and runs
# the tests under it. Destroy the host afterwards.
#
#   tools/fips-host/run.sh root@203.0.113.7   # from the repository's root; report: var/fips-host/report.txt
#
# It runs the commit that's checked out, and refuses a tree with changes: the report names that commit.
# ALLOW_DIRTY=1 runs one anyway, for trying the tooling, and the report says so. It exits with the
# tests' status.
#
# FIPS sshd refuses Ed25519 keys: use an ECDSA key, and pin the host's ECDSA key first.
set -euo pipefail
host=${1:?Usage: tools/fips-host/run.sh root@HOST}
work=var/fips-host
mkdir -p "$work"
changes=$(git status --porcelain)
if [ -n "$changes" ] && [ "${ALLOW_DIRTY:-}" != 1 ]; then
	echo "The working tree has changes: commit them, or set ALLOW_DIRTY=1 for a run that records nothing."
	exit 1
fi
{
	echo "== The run"
	echo "commit: $(git rev-parse HEAD)"
	echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
	if [ -n "$changes" ]; then echo "working tree: CHANGED (ALLOW_DIRTY=1), not a record"; printf '%s\n' "$changes"; else echo "working tree: clean"; fi
} > "$work/report.txt"
ssh_() { ssh -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=30 "$host" "$@"; }

if [ "$(ssh_ -n cat /proc/sys/crypto/fips_enabled)" != 1 ]; then
	echo "Switching it to FIPS mode, and rebooting"
	ssh_ -n 'fips-mode-setup --enable >/dev/null'
	ssh_ -n 'systemctl reboot' || true
	for _ in $(seq 1 60); do ssh_ -n true 2>/dev/null && break; sleep 5; done
fi
echo "Installing PHP 8.4"
ssh_ 'bash -s' < tools/fips-host/setup.sh
echo "Copying the working tree"
ssh_ -n 'rm -rf ~/yorvoid && mkdir ~/yorvoid'
# The working tree as git tracks it, .github included (git archive leaves out what export-ignore says).
git ls-files -z | tar --null -T - -cf - | ssh_ 'tar -x -C ~/yorvoid'
ssh_ -n 'cd ~/yorvoid && COMPOSER_ALLOW_SUPERUSER=1 composer install --quiet --no-interaction --no-progress'
status=0
ssh_ 'bash -s' < tools/fips-host/check.sh >> "$work/report.txt" 2>&1 || status=$?
echo "Report: $work/report.txt"
exit "$status"
