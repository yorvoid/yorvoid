<?php

declare(strict_types=1);

/*
 * A CycloneDX 1.6 SBOM of one of YorvoID's packages, as released: the package, and every package it
 * needs at the versions it was tested with (the monorepo's composer.lock, without development tools).
 *
 *   php tools/sbom/sbom.php packages/core 0.2.0 > yorvoid-core-0.2.0.cdx.json
 *   php tools/sbom/sbom.php packages/browser 0.2.0 > yorvoid-browser-0.2.0.cdx.json
 *
 * Written here rather than installed: it reads two files, and adds no dependency of its own.
 */

require __DIR__.'/Sbom.php';

$arguments = array_values(array_filter((array) ($_SERVER['argv'] ?? []), is_string(...)));

if (3 !== count($arguments)) {
    fwrite(\STDERR, "Usage: php tools/sbom/sbom.php <package directory> <version>\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

try {
    echo json_encode(YorvoID\Tools\Sbom\Sbom::of($root, $arguments[1], $arguments[2], gmdate('Y-m-d\TH:i:s\Z')), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), "\n";
} catch (RuntimeException $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
