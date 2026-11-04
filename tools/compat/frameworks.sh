#!/bin/sh
# Whether YorvoID's framework-free packages install next to the frameworks it promises to work with:
# Laravel 12 and Drupal 11, which are on Symfony 7.4. Composer only resolves (--dry-run); nothing is
# installed. Usage: sh tools/compat/frameworks.sh
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
# A version the packages' constraints on each other accept, e.g. 0.1.99 for ^0.1.
version=$(sed -n 's/.*"yorvoid\/core": *"^\([0-9]*\.[0-9]*\)".*/\1.99/p' "$root/packages/authn/composer.json")
versions=""

for directory in "$root"/packages/*/; do
	if [ -f "$directory/composer.json" ]; then
		versions="$versions\"yorvoid/$(basename "$directory")\": \"$version\", "
	fi
done

for framework in 'laravel/framework:^12' 'drupal/core-recommended:^11.3'; do
	name=${framework%%:*}
	constraint=${framework#*:}
	mkdir "$work/$(basename "$name")"
	# Every package at that one version, from this checkout.
	cat >"$work/$(basename "$name")/composer.json" <<JSON
{
    "name": "acme/compat",
    "minimum-stability": "stable",
    "repositories": [{"type": "path", "url": "$root/packages/*", "options": {"versions": {${versions%, }}}}],
    "require": {"$name": "$constraint", "yorvoid/passkeys": "$version", "yorvoid/mail": "$version", "yorvoid/translation": "$version", "yorvoid/intent-verifier": "$version"},
    "config": {"allow-plugins": false, "platform": {"ext-gd": "8.5.0"}}
}
JSON
	echo "$name $constraint:"
	(cd "$work/$(basename "$name")" && composer update --dry-run --no-interaction --no-audit --no-progress 2>&1 | grep -E 'Installing (yorvoid/passkeys|symfony/http-kernel|laravel/framework|drupal/core) ' || { composer update --dry-run --no-interaction --no-audit 2>&1 | tail -20; exit 1; })
done
