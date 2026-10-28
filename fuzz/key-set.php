<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Secret;

require __DIR__.'/bootstrap.php';

$config->setTarget(onlyRefusals(static function (string $input): void {
    KeyRing::fromJwks(Secret::fromString($input));
}));
$config->setMaxLen(1024);
