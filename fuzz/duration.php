<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Core\Duration;

require __DIR__.'/bootstrap.php';

$config->setTarget(onlyRefusals(static function (string $input): void {
    $duration = Duration::parse($input);

    if ((string) $duration !== $input && Duration::parse((string) $duration)->seconds !== $duration->seconds) {
        throw new Error('A parsed duration doesn\'t survive printing and parsing again.');
    }
}));
$config->setMaxLen(32);
