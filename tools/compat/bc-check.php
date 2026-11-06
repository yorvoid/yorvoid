<?php

declare(strict_types=1);

/*
 * Checks the compatibility promise (docs/compatibility.md): every package as it is now, against the same
 * package at the last version tag, with roave/backward-compatibility-check. Breaks in code the promise
 * doesn't cover (classes not marked @api, members marked @internal) are shown but allowed. Every other
 * break must name its class under "Breaking" in the changelog, in the Unreleased section or a release after
 * the tag, or the check fails.
 *
 * Each package is checked in a repository of its own, as it's installed: the monorepo's root has no
 * autoloading, so the tool would see no code there. Its composer.json finds the other YorvoID packages
 * in the same tree, so a package is compared with the code it's released with.
 *
 * Usage: php tools/compat/bc-check.php [tag], after composer install -d tools/compat/bc. The tag is the
 * last one before HEAD by default. Needs the network, for the packages' other dependencies.
 */

$root = dirname(__DIR__, 2);
$roave = $root.'/tools/compat/bc/vendor/bin/roave-backward-compatibility-check';

if (!is_file($roave)) {
    fwrite(\STDERR, "Install the tool first: composer install -d tools/compat/bc\n");
    exit(2);
}

$tag = $argv[1] ?? trim(run(['git', '-C', $root, 'describe', '--tags', '--abbrev=0', '--match', 'v*']));
$work = rtrim(sys_get_temp_dir(), '/').'/yorvoid-bc-'.bin2hex(random_bytes(4));
register_shutdown_function(static fn () => run(['rm', '-rf', $work]));

// Both trees: the packages at the tag, and as they are now (uncommitted changes included).
mkdir("$work/from", 0o777, true);
run(['sh', '-c', sprintf('git -C %s archive %s packages | tar -x -C %s', escapeshellarg($root), escapeshellarg($tag), escapeshellarg("$work/from"))]);

foreach (array_filter(explode("\0", run(['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard', 'packages'])), static fn (string $file): bool => '' !== $file) as $file) {
    if (is_file("$root/$file")) {
        @mkdir(dirname("$work/to/$file"), 0o777, true);
        copy("$root/$file", "$work/to/$file");
    }
}

$listed = breakingSince((string) file_get_contents("$root/CHANGELOG.md"), ltrim($tag, 'v'));
$unlisted = 0;
echo "Compared with $tag.\n";

foreach (orNone(glob("$work/to/packages/*/composer.json")) as $manifest) {
    $package = basename(dirname($manifest));

    if (!is_file("$work/from/packages/$package/composer.json")) {
        echo "\nyorvoid/$package: new since $tag.\n";

        continue;
    }

    $repository = "$work/repository/$package";
    mkdir($repository, 0o777, true);
    run(['git', '-C', $repository, 'init', '-q']);
    $commits = [];

    foreach (['from', 'to'] as $side) {
        run(['sh', '-c', sprintf('cd %s && git rm -rqf --ignore-unmatch . && cp -R %s/. .', escapeshellarg($repository), escapeshellarg("$work/$side/packages/$package"))]);
        installable("$repository/composer.json", "$work/$side/packages");
        run(['git', '-C', $repository, 'add', '-A']);
        run(['git', '-C', $repository, '-c', 'user.name=bc', '-c', 'user.email=bc@localhost', 'commit', '-qm', $side, '--allow-empty']);
        $commits[$side] = trim(run(['git', '-C', $repository, 'rev-parse', 'HEAD']));
    }

    $output = run([\PHP_BINARY, $roave, '--from='.$commits['from'], '--to='.$commits['to'], '--format=markdown'], $repository, allowFailure: true);
    preg_match_all('/^\s*- \[BC\] (.+)$/m', $output, $breaks);
    echo "\nyorvoid/$package: ".count($breaks[1])." change(s) that break compatibility.\n";

    foreach ($breaks[1] as $break) {
        $verdict = verdict($break, "$work/from/packages/$package", $listed);
        echo "  [$verdict] $break\n";
        $unlisted += 'NOT IN THE CHANGELOG' === $verdict ? 1 : 0;
    }
}

if ($unlisted > 0) {
    echo "\n$unlisted break(s) of covered code aren't in the changelog: list each under \"### Breaking\" in \"## Unreleased\", naming its class, or keep the code compatible.\n";
    exit(1);
}

echo "\nEvery break of covered code is in the changelog.\n";

/**
 * Whether a break counts: outside the promise, accepted in bc-ignore.txt, listed, or not.
 */
function verdict(string $break, string $before, string $listed): string
{
    foreach (ignored() as $pattern) {
        if (1 === preg_match($pattern, $break)) {
            return 'accepted';
        }
    }

    if (1 !== preg_match('/YorvoID(?:\\\\\w+)+/', $break, $class)) {
        return 'NOT IN THE CHANGELOG';
    }

    $source = source($class[0], $before);
    $short = substr($class[0], (int) strrpos($class[0], '\\') + 1);
    $name = null;

    if (1 === preg_match('/#(\w+)\(|Method (\w+)\(\)/', $break, $method)) {
        $name = '' !== ($method[2] ?? '') ? $method[2] : $method[1];
    }

    if (null !== $source) {
        if (!documented($source, '(?:(?:final|abstract|readonly)\s+)*(?:class|interface|enum|trait)\s+'.preg_quote($short, '/').'\b', '@api')) {
            return 'not @api';
        }

        if (null !== $name && documented($source, '(?:(?:final|abstract|public|protected|private|static)\s+)*function\s+'.preg_quote($name, '/').'\s*\(', '@internal')) {
            return '@internal';
        }
    }

    // The class, or for a method the method too, as in `SessionStore::stepUp()`, which covers its implementations.
    $named = 1 === preg_match('/\b'.preg_quote($short, '/').'\b/', $listed) || (null !== $name && 1 === preg_match('/\b'.preg_quote($name, '/').'\(\)/', $listed));

    return $named ? 'in the changelog' : 'NOT IN THE CHANGELOG';
}

/**
 * The breaks accepted once and for all, with why: bc-ignore.txt, one pattern a line.
 *
 * @return list<string>
 */
function ignored(): array
{
    /** @var list<string>|null $patterns */
    static $patterns = null;

    if (null === $patterns) {
        $patterns = [];

        foreach (orNone(file(__DIR__.'/bc-ignore.txt', \FILE_IGNORE_NEW_LINES)) as $line) {
            if ('' !== trim($line) && !str_starts_with($line, '#')) {
                $patterns[] = '/'.str_replace('/', '\/', trim($line)).'/';
            }
        }
    }

    return $patterns;
}

/**
 * The source of a class in a package's tree, by its PSR-4 autoloading.
 */
function source(string $class, string $package): ?string
{
    $manifest = json_decode((string) file_get_contents("$package/composer.json"), true);
    $autoload = is_array($manifest) && is_array($manifest['autoload'] ?? null) ? $manifest['autoload'] : [];
    $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

    foreach ($psr4 as $prefix => $directory) {
        if (is_string($prefix) && is_string($directory) && str_starts_with($class, $prefix)) {
            $file = "$package/".rtrim($directory, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            return is_file($file) ? (string) file_get_contents($file) : null;
        }
    }

    return null;
}

/**
 * Whether the docblock right before a declaration has a tag; attributes may stand between them.
 */
function documented(string $source, string $declaration, string $tag): bool
{
    return 1 === preg_match('/\/\*\*((?:(?!\*\/).)*)\*\/\s*(?:#\[[^\n]*\]\s*)*'.$declaration.'/s', $source, $doc) && str_contains($doc[1], $tag);
}

/**
 * The changelog's Breaking lists of every release after the tag's, the coming one (Unreleased) included.
 */
function breakingSince(string $changelog, string $version): string
{
    $listed = '';

    foreach (orNone(preg_split('/^(?=## )/m', $changelog)) as $section) {
        if (str_starts_with($section, '## '.$version.' ') || str_starts_with($section, '## '.$version."\n")) {
            break;
        }

        if (1 === preg_match('/^### Breaking\b(.*?)(?=^##|\z)/ms', $section, $breaking)) {
            $listed .= $breaking[1];
        }
    }

    return $listed;
}

/**
 * Points the package's composer.json at the other packages of the same tree, each with a version its
 * constraints accept (0.2.99 for ^0.2), as tools/compat/frameworks.sh does.
 */
function installable(string $manifest, string $packages): void
{
    $json = json_decode((string) file_get_contents($manifest), true, 512, \JSON_THROW_ON_ERROR);
    assert(is_array($json));
    $version = null;

    foreach ((array) ($json['require'] ?? []) as $name => $constraint) {
        if (is_string($name) && is_string($constraint) && str_starts_with($name, 'yorvoid/') && 1 === preg_match('/^\^(\d+\.\d+)/', $constraint, $match)) {
            $version = $match[1].'.99';
        }
    }

    if (null === $version) {
        return;
    }

    $versions = [];

    foreach (orNone(glob("$packages/*/composer.json")) as $other) {
        $versions['yorvoid/'.basename(dirname($other))] = $version;
    }

    $json['repositories'] = [['type' => 'path', 'url' => "$packages/*", 'options' => ['symlink' => false, 'versions' => $versions]]];
    file_put_contents($manifest, json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
}

/**
 * @param list<string>|false $values
 *
 * @return list<string>
 */
function orNone(array|false $values): array
{
    return false === $values ? [] : $values;
}

/**
 * @param list<string> $command
 */
function run(array $command, ?string $directory = null, bool $allowFailure = false): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    assert(is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);

    if (0 !== $status && !$allowFailure) {
        fwrite(\STDERR, implode(' ', $command)." failed:\n$errors");
        exit(2);
    }

    return $output;
}
