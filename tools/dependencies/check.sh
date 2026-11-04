#!/bin/sh
# Each package uses only what its composer.json requires (REPOS-03): composer-dependency-analyser, run on
# every package as if it were the root, with the monorepo's vendor directory.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
status=0

for package in "$root"/packages/*/composer.json; do
    directory=$(dirname "$package")
    [ -d "$directory/src" ] || continue
    work=$(mktemp -d)
    cp "$package" "$work/composer.json"
    ln -s "$root/vendor" "$work/vendor"
    ln -s "$directory/src" "$work/src"
    echo "== $(basename "$directory")"
    YORVOID_PACKAGE=$(basename "$directory") php "$root/vendor/bin/composer-dependency-analyser" --composer-json "$work/composer.json" --config "$root/tools/dependencies/config.php" || status=1
    rm -rf "$work"
done

exit $status
