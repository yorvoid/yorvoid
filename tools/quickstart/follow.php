<?php

declare(strict_types=1);

/*
 * Follows the Symfony quickstart (docs/tutorials/symfony-quickstart.md) in var/quickstart, exactly as
 * it's written: the commands it says to run, the files it says to write. Comments in the tutorial
 * mark each step, invisibly:
 *
 *   <!-- quickstart: run -->                  run the block's commands ("cd" moves on)
 *   <!-- quickstart: write PATH -->           write the block as the file
 *   <!-- quickstart: insert PATH before X --> insert the block before the first X in the file
 *   <!-- quickstart: setup-code -->           run it, and keep its output for the browser test
 *   <!-- quickstart: serve -->                skip it: the "quickstart-app" service serves the app
 *
 * Until YorvoID is on Packagist, Composer installs it from this repository's packages; with
 * QUICKSTART_PACKAGIST=1, from Packagist, as a newcomer would.
 *
 * Run: docker compose exec php php tools/quickstart/follow.php
 */

$root = dirname(__DIR__, 2);
$work = $root.'/var/quickstart';
$markdown = (string) file_get_contents($root.'/docs/tutorials/symfony-quickstart.md');
preg_match_all('/<!-- quickstart: (?<step>[^\n]+?) -->\n```[a-z]*\n(?<body>.*?)```/s', $markdown, $steps, \PREG_SET_ORDER);

if ([] === $steps) {
    fwrite(\STDERR, "The tutorial has no marked steps.\n");
    exit(1);
}

exec('rm -rf '.escapeshellarg($work));
mkdir($work, 0o777, true);
putenv('COMPOSER_NO_INTERACTION=1');
$fromRepository = '1' !== getenv('QUICKSTART_PACKAGIST');
$cwd = $work;
$started = microtime(true);

$run = static function (string $command, string $cwd): string {
    echo "\n\$ {$command}\n";
    $lines = [];
    $process = popen(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), 'r');
    assert(is_resource($process));

    while (false !== ($line = fgets($process))) {
        echo $line;
        $lines[] = $line;
    }

    if (0 !== pclose($process)) {
        fwrite(\STDERR, "\nThe quickstart failed at: {$command}\n");
        exit(1);
    }

    return implode('', $lines);
};

foreach ($steps as $step) {
    [$action, $argument] = explode(' ', trim($step['step']), 2) + [1 => ''];
    $body = $step['body'];

    switch ($action) {
        case 'run':
            foreach (array_filter(array_map(trim(...), explode("\n", $body)), static fn (string $line): bool => '' !== $line) as $command) {
                if (str_starts_with($command, 'cd ')) {
                    $cwd .= '/'.substr($command, 3);
                    echo "\n\$ {$command}\n";

                    continue;
                }

                // YorvoID isn't on Packagist yet: the application takes it from this repository. Each
                // package gets the version main has, as on Packagist: Composer would otherwise guess it
                // from the checkout, and a detached one (as in CI) gives versions "^0.1" doesn't match.
                if ($fromRepository && str_contains($command, 'composer require') && str_contains($command, 'yorvoid/')) {
                    $versions = [];
                    $files = glob($root.'/packages/*/composer.json');
                    assert(is_array($files));
                    foreach ($files as $file) {
                        $package = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
                        assert(is_array($package) && is_string($package['name']) && is_array($package['extra']) && is_array($package['extra']['branch-alias']) && is_string($package['extra']['branch-alias']['dev-main']));
                        $versions[$package['name']] = $package['extra']['branch-alias']['dev-main'];
                    }
                    $repository = ['type' => 'path', 'url' => $root.'/packages/*', 'options' => ['versions' => $versions]];
                    $run('composer config repositories.yorvoid '.escapeshellarg(json_encode($repository, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)), $cwd);
                    $run('composer config minimum-stability dev && composer config prefer-stable true', $cwd);
                }

                $run($command, $cwd);
            }

            break;

        case 'write':
            echo "\n# writes {$argument}\n";
            @mkdir(dirname($cwd.'/'.$argument), 0o777, true);
            file_put_contents($cwd.'/'.$argument, $body);

            break;

        case 'insert':
            [$file, $needle] = explode(' before ', $argument, 2);
            echo "\n# adds to {$file}\n";
            $content = (string) file_get_contents($cwd.'/'.$file);
            $at = strpos($content, $needle);

            if (false === $at) {
                fwrite(\STDERR, "\n{$file} has no \"{$needle}\" to insert before.\n");
                exit(1);
            }

            file_put_contents($cwd.'/'.$file, substr_replace($content, $body, $at, 0));

            break;

        case 'setup-code':
            file_put_contents($work.'/setup-code.txt', trim($run(trim($body), $cwd)));

            break;

        case 'serve':
            file_put_contents($work.'/app', substr($cwd, strlen($root) + 1));

            break;

        default:
            fwrite(\STDERR, "Unknown quickstart step: {$action}\n");
            exit(1);
    }
}

printf("\nFollowed the quickstart's %d steps in %d seconds, without the browser.\n", count($steps), (int) round(microtime(true) - $started));
