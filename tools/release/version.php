<?php

declare(strict_types=1);

/*
 * The version main is heading for, in every place that names it: each package's branch alias
 * (0.4.x-dev), what the packages require of each other (^0.4), the monorepo's own composer.json, the
 * store kit's project and the snippets in the docs. All packages are released together, so a set
 * installs only when they require each other at the version they're released with (ADR-0005).
 *
 *   php tools/release/version.php check          every place agrees with packages/core's branch alias
 *   php tools/release/version.php check v0.4.0   …and the tag is of that minor
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
}

foreach ($composerFiles as $path) {
    // Read as objects, so that {} stays {}: written back the way Composer writes it.
    $json = json_decode((string) file_get_contents($path), false, flags: \JSON_THROW_ON_ERROR);
    $changed = false;

    foreach (['require', 'require-dev'] as $section) {
        $requires = $members($json)[$section] ?? null;

        foreach ($members($requires) as $name => $required) {
            if (str_starts_with($name, 'yorvoid/') && $required !== $constraint) {
                assert($requires instanceof stdClass);
                $problems[] = sprintf('%s requires %s at "%s", not "%s".', $relative($path), $name, $text($required), $constraint);
                $requires->{$name} = $constraint;
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

echo sprintf("Every package requires the others at %s, and main is %s.\n", $constraint, $dev);
