#!/bin/sh
# Refuses a version tag that isn't annotated and signed by a key in .github/release-signers, as main
# lists them: a tag's own commit can't add its signer. Usage: .github/verify-tag.sh v0.2.0
set -eu

tag=$1
signers=$(mktemp)
trap 'rm -f "$signers"' EXIT
git show origin/main:.github/release-signers >"$signers"

# The tag object itself, which a checkout may not have fetched.
git fetch --quiet --no-tags --force origin "refs/tags/$tag:refs/tags/$tag"

if [ "$(git cat-file -t "refs/tags/$tag")" != tag ]; then
	echo "::error::The tag $tag isn't annotated, so it isn't signed: make it with git tag -s."
	exit 1
fi

if ! git -c gpg.format=ssh -c gpg.ssh.allowedSignersFile="$signers" tag -v "$tag"; then
	echo "::error::The tag $tag isn't signed by a key in .github/release-signers."
	exit 1
fi
