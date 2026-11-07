#!/bin/sh
# Whether a version tag may be released: signed by a key in .github/release-signers (verify-tag.sh),
# on a commit of main that passed CI, whose packages require each other at the tag's version. The
# Split, npm and Release workflows all ask it.
# Usage: REPOSITORY=owner/name GH_TOKEN=… .github/release-check.sh v0.2.0
set -eu

tag=$1
.github/verify-tag.sh "$tag"

# A set installs only when its packages require each other at the version they're released with.
if ! php tools/release/version.php check "$tag"; then
	echo "::error::The packages don't require each other at $tag's version."
	exit 1
fi

commit=$(git rev-parse "refs/tags/$tag^{commit}")

if ! git merge-base --is-ancestor "$commit" origin/main; then
	echo "::error::The tag's commit $commit isn't on main."
	exit 1
fi

passed=$(gh api "repos/$REPOSITORY/commits/$commit/check-runs?check_name=CI" --jq '[.check_runs[] | select(.conclusion == "success")] | length')

if [ "$passed" -eq 0 ]; then
	echo "::error::CI hasn't passed for the tag's commit $commit."
	exit 1
fi
