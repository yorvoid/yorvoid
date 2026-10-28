#!/usr/bin/env bash
# Splits each directory listed in .github/split.json off into the history of its own repository, the
# read-only copy that Composer installs it from. The same history always gives the same commits, so
# every push is a fast-forward.
#
#   .github/split.sh <ref>          shows the commit of each repository at <ref>
#   .github/split.sh <ref> --push   pushes them: a branch to that branch, a tag to the same tag
#
# Pushing needs a token that may write to the repositories, in GIT_TOKEN. SPLIT_REMOTE replaces
# https://github.com, e.g. to try it with local repositories.
set -euo pipefail

ref=${1:?Usage: .github/split.sh <ref> [--push]}
push=${2:-}

case "$ref" in
	refs/heads/* | refs/tags/*) target=$ref ;;
	*) target=refs/heads/$ref ;;
esac

if [ "$push" = '--push' ]; then
	auth=$(printf 'x-access-token:%s' "${GIT_TOKEN:?GIT_TOKEN is needed to push}" | base64 -w0)
	if [ -n "${GITHUB_ACTIONS:-}" ]; then
		echo "::add-mask::$auth"
	fi
fi

commit=$(git rev-parse --verify "$ref^{commit}")

jq -r '.[] | "\(.path) \(.repository)"' "$(dirname "$0")/split.json" | while read -r path repository; do
	split=$(git subtree split --quiet --prefix="$path" "$commit")
	echo "$path at $commit: $repository $split"

	if [ "$push" = '--push' ]; then
		git -c http.extraheader="AUTHORIZATION: basic $auth" push --quiet "${SPLIT_REMOTE:-https://github.com}/$repository.git" "$split:$target"
	fi
done
