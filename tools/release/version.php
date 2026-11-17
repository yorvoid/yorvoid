<?php

declare(strict_types=1);

/*
 * The version main is heading for, in every place that names it: each package's branch alias
 * (0.4.x-dev), what the packages require of each other (^0.4; ~1.2.0 after 1.0), the monorepo's own composer.json, the
 * store kit's project and the snippets in the docs. All packages are released together, so a set
 * installs only when they require each other at the version they're released with (ADR-0005).
 *
 *   php tools/release/version.php check          every place agrees with packages/core's branch alias
 *   php tools/release/version.php check v0.4.0   …and the tag is of that minor, and Packagist has every
 *                                                package Split pushes (.github/split.json): run it before tagging
 *   php tools/release/version.php set 0.5        main heads for 0.5 now: right after tagging 0.4.0
 *
 * Plain PHP 8.1, no dependencies: the release workflows run it on the runner's own PHP.
 */

$root = dirname(__DIR__, 2);
[$action, $argument] = [$argv[1] ?? '', $argv[2] ?? null];

if (!in_array($action, ['check', 'set'], true) || ('set' === $action && (null === $argument || 1 !== preg_match('/^\d+\.\d+$/D', $argument)))) {
    fwrite(\STDERR, "Usage: php tools/release/version.php check [vX.Y.Z] | set X.Y\n");
    exit(2);
}

/** @return list<string> */
$files = static function (string $pattern): array {
    $found = glob($pattern);

    return false === $found ? [] : $found;
};
/** The JSON object's members; none for anything else */
$members = static fn (mixed $value): array => $value instanceof stdClass ? get_object_vars($value) : [];
$composerFiles = [...$files($root.'/packages/*/composer.json'), $root.'/composer.json', $root.'/tools/store-kit/project/composer.json'];
$docs = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/docs', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file instanceof SplFileInfo && 'md' === $file->getExtension()) {
        $docs[] = $file->getPathname();
    }
}

sort($docs);
$core = json_decode((string) file_get_contents($root.'/packages/core/composer.json'), false, flags: \JSON_THROW_ON_ERROR);
$alias = $members($members($members($core)['extra'] ?? null)['branch-alias'] ?? null)['dev-main'] ?? null;

if (!is_string($alias) || 1 !== preg_match('/^(\d+\.\d+)\.x-dev$/D', $alias, $match)) {
    fwrite(\STDERR, "packages/core/composer.json has no branch alias like 0.4.x-dev for dev-main.\n");
    exit(1);
}

$minor = 'set' === $action ? $argument : $match[1];
$constraint = '^'.$minor;
// What the packages require of each other: the same minor, also after 1.0 (~1.2.0, not ^1.2). A
// package may use a sibling's @internal members, which are added, changed or removed only in a minor
// release (D-41), so a set mixes patch releases of one minor, never two minors.
$sibling = str_starts_with($minor, '0.') ? $constraint : '~'.$minor.'.0';
$dev = $minor.'.x-dev';
$problems = [];
$relative = static fn (string $path): string => substr($path, strlen($root) + 1);
$text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, \JSON_THROW_ON_ERROR);

if ('check' === $action && null !== $argument) {
    if (1 !== preg_match('/^v(\d+\.\d+)\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $argument, $tag)) {
        $problems[] = sprintf('"%s" isn\'t a version tag like v0.4.0.', $argument);
    } elseif ($tag[1] !== $minor) {
        $problems[] = sprintf('The tag %s is of %s, but the packages require each other at %s: run "php tools/release/version.php set %s" in a pull request first.', $argument, $tag[1], $constraint, $tag[1]);
    }

    // A package Packagist doesn't know installs from nowhere, and nor does any package that requires it.
    $split = json_decode((string) file_get_contents($root.'/.github/split.json'), false, flags: \JSON_THROW_ON_ERROR);

    foreach (is_array($split) ? $split : [] as $index => $entry) {
        [$path, $repository] = [$members($entry)['path'] ?? null, $members($entry)['repository'] ?? null];

        if (!is_string($path) || !is_string($repository) || !is_file($root.'/'.$path.'/composer.json')) {
            $problems[] = sprintf('.github/split.json\'s entry %d has no path to a package or no repository.', $index + 1);

            continue;
        }

        // Packagist knows a package by the name in its composer.json, and installs it from the repository.
        $name = $members(json_decode((string) file_get_contents($root.'/'.$path.'/composer.json'), false, flags: \JSON_THROW_ON_ERROR))['name'] ?? null;

        if (!is_string($name)) {
            $problems[] = sprintf('%s/composer.json has no name.', $path);

            continue;
        }

        $metadata = @file_get_contents('https://packagist.org/packages/'.$name.'.json');
        $source = false === $metadata ? null : $members($members(json_decode($metadata, false))['package'] ?? null)['repository'] ?? null;

        if (false === $metadata) {
            $problems[] = sprintf('Packagist has no %s: submit https://github.com/%s at https://packagist.org/packages/submit before tagging (or Packagist didn\'t answer: try again).', $name, $repository);
        } elseif ($source !== 'https://github.com/'.$repository) {
            $problems[] = sprintf('Packagist has %s from "%s", not from https://github.com/%s, where Split pushes it.', $name, $text($source), $repository);
        }
    }
}

foreach ($composerFiles as $path) {
    // Read as objects, so that {} stays {}: written back the way Composer writes it.
    $json = json_decode((string) file_get_contents($path), false, flags: \JSON_THROW_ON_ERROR);
    $changed = false;

    foreach (['require', 'require-dev'] as $section) {
        $requires = $members($json)[$section] ?? null;

        foreach ($members($requires) as $name => $required) {
            if (str_starts_with($name, 'yorvoid/') && $required !== $sibling) {
                assert($requires instanceof stdClass);
                $problems[] = sprintf('%s requires %s at "%s", not "%s".', $relative($path), $name, $text($required), $sibling);
                $requires->{$name} = $sibling;
                $changed = true;
            }
        }
    }

    $aliases = $members($members($json)['extra'] ?? null)['branch-alias'] ?? null;
    $branchAlias = $members($aliases)['dev-main'] ?? null;

    if (null !== $branchAlias && $branchAlias !== $dev) {
        assert($aliases instanceof stdClass);
        $problems[] = sprintf('%s aliases dev-main as "%s", not "%s".', $relative($path), $text($branchAlias), $dev);
        $aliases->{'dev-main'} = $dev;
        $changed = true;
    }

    $repositories = $members($json)['repositories'] ?? [];

    foreach (is_array($repositories) ? $repositories : [] as $repository) {
        $versions = $members($members($repository)['options'] ?? null)['versions'] ?? null;

        foreach ($members($versions) as $name => $version) {
            if ($version !== $dev) {
                assert($versions instanceof stdClass);
                $problems[] = sprintf('%s gives %s from this repository the version "%s", not "%s".', $relative($path), $name, $text($version), $dev);
                $versions->{$name} = $dev;
                $changed = true;
            }
        }
    }

    if ('set' === $action && $changed) {
        file_put_contents($path, json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
    }
}

foreach ($docs as $path) {
    $text = (string) file_get_contents($path);
    $updated = preg_replace_callback('#("yorvoid/[a-z-]+":\s*)"(\^\d+\.\d+)"#', static function (array $found) use ($constraint, $path, $relative, &$problems): string {
        if ($found[2] !== $constraint) {
            $problems[] = sprintf('%s shows "%s" where the packages are at "%s".', $relative($path), $found[2], $constraint);
        }

        return $found[1].'"'.$constraint.'"';
    }, $text) ?? $text;

    if ('set' === $action && $updated !== $text) {
        file_put_contents($path, $updated);
    }
}

if ('set' === $action) {
    echo sprintf("Main heads for %s now: %d place(s) changed. Run \"composer update 'yorvoid/*'\" for the lock file.\n", $minor, count($problems));
    exit(0);
}

if ([] !== $problems) {
    foreach ($problems as $problem) {
        echo $problem, "\n";
    }

    exit(1);
}

echo sprintf("Every package requires the others at %s, and main is %s.%s\n", $sibling, $dev, null !== $argument ? ' Packagist has every package.' : '');
