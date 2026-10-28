<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\InvalidIdentifier;

require __DIR__.'/bootstrap.php';

// What people type as their email address or username: refused with InvalidIdentifier, or accepted in a
// form that is valid UTF-8, fits the database's column, and is its own normalized form.
$config->setTarget(onlyRefusals(static function (string $input): void {
    foreach (IdentifierKind::cases() as $kind) {
        try {
            $normalized = $kind->normalize($input);
        } catch (InvalidIdentifier) {
            continue;
        }

        if (1 !== preg_match('//u', $normalized) || strlen($normalized) > 254 || $kind->normalize($normalized) !== $normalized) {
            throw new LogicException(sprintf('The %s "%s" was accepted as "%s".', $kind->value, $input, $normalized));
        }
    }
}));
$config->setMaxLen(300);
