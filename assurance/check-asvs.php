<?php

declare(strict_types=1);

/*
 * Compares assurance/asvs-5.0.yaml with OWASP's own list of ASVS 5.0.0 requirements (the release
 * file, verified by its SHA-256): the same IDs, with the same levels. Run in CI: php assurance/check-asvs.php
 */

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

$mapping = Yaml::parseFile(__DIR__.'/asvs-5.0.yaml');
$json = file_get_contents($mapping['source']);

if (false === $json || !hash_equals($mapping['source_sha256'], hash('sha256', $json))) {
    fwrite(\STDERR, "OWASP's file couldn't be downloaded, or isn't the one this mapping was made from.\n");
    exit(1);
}

$official = [];

foreach (json_decode($json, true, 16, \JSON_THROW_ON_ERROR)['requirements'] as $requirement) {
    $official[$requirement['req_id']] = (int) $requirement['L'];
}

$ours = array_map(static fn (array $requirement): int => $requirement['level'], $mapping['requirements']);
$problems = [];

foreach (array_diff_key($official, $ours) as $id => $level) {
    $problems[] = "$id is in the standard but not in the mapping";
}

foreach (array_diff_key($ours, $official) as $id => $level) {
    $problems[] = "$id is in the mapping but not in the standard";
}

foreach (array_intersect_key($ours, $official) as $id => $level) {
    if ($official[$id] !== $level) {
        $problems[] = "$id is level {$official[$id]} in the standard, $level in the mapping";
    }
}

echo [] === $problems ? sprintf("The mapping lists all %d requirements of ASVS 5.0.0, with their levels.\n", count($official)) : implode("\n", $problems)."\n";
exit([] === $problems ? 0 : 1);
