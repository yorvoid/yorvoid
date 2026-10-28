<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Core\Realm;

require __DIR__.'/bootstrap.php';

$config->setTarget(onlyRefusals(static function (string $input): void {
    new Realm($input);
}));
$config->setMaxLen(80);
